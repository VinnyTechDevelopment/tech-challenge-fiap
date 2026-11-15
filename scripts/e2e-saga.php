<?php

/**
 * Teste E2E da Saga contra o ambiente real (docker-compose.microservices.yml
 * ou EKS via API Gateway). Também serve de roteiro para a demonstração.
 *
 *   php scripts/e2e-saga.php                       # local (portas 8080/8083/8084)
 *   OS_URL=https://<gw> BILLING_URL=https://<gw> EXECUTION_URL=https://<gw> php scripts/e2e-saga.php
 *
 * Cenário 1: fluxo completo (orçamento -> aprovação -> pagamento -> execução -> entrega)
 * Cenário 2: falta de peças -> estorno do pagamento -> OS cancelada (compensação)
 *
 * Requer PAYMENT_GATEWAY=fake no billing (o link de pagamento é o simulador).
 */
$os = rtrim(getenv('OS_URL') ?: 'http://localhost:8080', '/');
$billing = rtrim(getenv('BILLING_URL') ?: 'http://localhost:8084', '/');
$execution = rtrim(getenv('EXECUTION_URL') ?: 'http://localhost:8083', '/');
$timeout = (int) (getenv('E2E_TIMEOUT') ?: 60);

function http(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$status, json_decode((string) $raw, true) ?? ['raw' => $raw]];
}

function expect(bool $condition, string $message, mixed $context = null): void
{
    if (! $condition) {
        fwrite(STDERR, "\n  ✗ {$message}\n".($context !== null ? '    '.json_encode($context, JSON_UNESCAPED_UNICODE)."\n" : ''));
        exit(1);
    }
    echo "  ✓ {$message}\n";
}

function waitFor(callable $probe, string $what, int $timeout): mixed
{
    $deadline = time() + $timeout;
    do {
        if (($result = $probe()) !== null) {
            return $result;
        }
        usleep(500_000);
    } while (time() < $deadline);

    fwrite(STDERR, "\n  ✗ timeout esperando: {$what}\n");
    exit(1);
}

function orderStatus(string $os, string $id, string $token): array
{
    [, $body] = http('GET', "{$os}/api/service-order/{$id}", null, $token);

    return $body;
}

$run = substr(md5((string) microtime(true)), 0, 6);

echo "\n== Preparação ==\n";
$email = "atendente.{$run}@oficina.dev";
$cpf = (function (): string {
    $n = array_map(fn () => random_int(0, 9), range(1, 9));
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += $n[$i] * (($t + 1) - $i);
        }
        $n[$t] = ((10 * $sum) % 11) % 10;
    }

    return implode('', $n);
});
[$s] = http('POST', "{$os}/api/auth/register", ['name' => 'Atendente E2E', 'email' => $email, 'document' => $cpf(), 'password' => 'secret123', 'password_confirmation' => 'secret123']);
expect($s === 201, "funcionário registrado no os-service ({$email})");
[$s, $login] = http('POST', "{$os}/api/auth/login", ['email' => $email, 'password' => 'secret123']);
$token = $login['access_token'] ?? null;
expect($s === 200 && $token, 'login JWT de funcionário');

[$s, $svc] = http('POST', "{$execution}/api/catalog/services", ['name' => "Revisao completa {$run}", 'price' => 300], $token);
expect($s < 300 && isset($svc['id']), 'serviço cadastrado no catálogo (execution-service, DynamoDB) — mesmo JWT');
[$s, $item] = http('POST', "{$execution}/api/catalog/items", ['name' => 'Filtro de oleo', 'code' => "FLT-{$run}", 'type' => 'part', 'measure_unit' => 'un', 'minimum_quantity' => 1, 'unit_price' => 45.5], $token);
expect($s === 201, 'peça cadastrada no catálogo');
[$s] = http('POST', "{$execution}/api/catalog/items/{$item['id']}/stock/entry", ['quantity' => 3, 'reason' => 'estoque inicial'], $token);
expect($s === 201, 'entrada de 3 unidades no estoque');

[$s, $customer] = http('POST', "{$os}/api/customer", ['name' => 'Cliente E2E', 'email' => "cli.{$run}@ex.com", 'phone' => '11999990000', 'document' => $cpf()], $token);
expect($s < 300, 'cliente cadastrado (os-service)', $customer);
[$s, $vehicle] = http('POST', "{$os}/api/vehicle", ['brand' => 'Fiat', 'model' => 'Argo', 'year' => 2022, 'plate' => chr(random_int(65, 90)).chr(random_int(65, 90)).'E'.random_int(1, 9).'A'.random_int(10, 99)], $token);
expect($s < 300, 'veículo cadastrado (os-service)', $vehicle);

$openOrder = function (int $qty) use ($os, $token, $customer, $vehicle, $svc, $item): array {
    [$s, $body] = http('POST', "{$os}/api/service-order", [
        'customer_id' => $customer['customer']['id'],
        'vehicle_id' => $vehicle['id'],
        'services' => [['service_id' => $svc['id'], 'quantity' => 1]],
        'items' => [['item_id' => $item['id'], 'quantity' => $qty]],
    ], $token);
    expect($s === 202, "OS aberta (202) com {$qty} peça(s) — preço vindo do execution-service (REST): R$ {$body['service_order']['total_budget']}", $body);

    return $body;
};

$payOrder = function (string $id) use ($os, $token, $timeout): void {
    $saga = waitFor(function () use ($os, $id, $token) {
        $o = orderStatus($os, $id, $token);

        return ($o['service_order']['status'] ?? '') === 'aguardando_aprovacao' ? $o['saga'] : null;
    }, 'orçamento gerado pelo billing', $timeout);
    expect(true, "billing gerou o orçamento (SQS) → OS aguardando_aprovacao; link: {$saga['approval_url']}");

    [$s, $approved] = http('GET', $saga['approval_url']);
    expect($s === 200, "cliente aprovou o orçamento; link de pagamento: {$approved['payment_url']}", $approved);

    waitFor(fn () => (orderStatus($os, $id, $token)['service_order']['status'] ?? '') === 'aguardando_pagamento' ? true : null, 'aguardando_pagamento', $timeout);
    expect(true, 'OS aguardando_pagamento');

    [$s] = http('POST', $approved['payment_url'], ['status' => 'approved']);
    expect($s === 200, 'pagamento aprovado (notificação do gateway)');
};

echo "\n== Cenário 1: fluxo completo de uma OS pelos 3 microsserviços ==\n";
$order = $openOrder(2)['service_order'];
$payOrder($order['id']);

waitFor(fn () => (orderStatus($os, $order['id'], $token)['service_order']['status'] ?? '') === 'aguardando_execucao' ? true : null, 'aguardando_execucao', $timeout);
expect(true, 'execution-service reservou as peças (DynamoDB TransactWriteItems) → OS aguardando_execucao');

[$s, $queue] = http('GET', "{$execution}/api/execution/queue", null, $token);
expect(in_array($order['id'], array_column($queue['data'] ?? [], 'service_order_id'), true), 'OS está na fila de execução');
[, $stock] = http('GET', "{$execution}/api/catalog/items/{$item['id']}", null, $token);
expect(($stock['stock_quantity'] ?? null) == 1, 'estoque baixou de 3 para 1');

foreach (['diagnosis' => 'em_diagnostico', 'repair' => 'em_execucao', 'finish' => 'finalizada'] as $action => $expected) {
    [$s] = http('PATCH', "{$execution}/api/execution/{$order['id']}/{$action}", ['notes' => "e2e {$action}"], $token);
    expect($s === 200, "mecânico: {$action}");
    waitFor(fn () => (orderStatus($os, $order['id'], $token)['service_order']['status'] ?? '') === $expected ? true : null, $expected, $timeout);
    expect(true, "OS {$expected} (evento do execution-service via SQS)");
}

[$s] = http('PATCH', "{$os}/api/service-order/{$order['id']}/status", ['status' => 'entregue'], $token);
expect($s === 200, 'atendente registrou a entrega → OS entregue');
[, $history] = http('GET', "{$os}/api/service-order/{$order['id']}/history", null, $token);
expect(($history['saga']['state'] ?? '') === 'COMPLETED', 'saga COMPLETED');
echo '    histórico: '.implode(' → ', array_column($history['history'], 'to_status'))."\n";

echo "\n== Cenário 2: falta de peças → estorno → OS cancelada (compensação) ==\n";
$order2 = $openOrder(5)['service_order'];
$payOrder($order2['id']);

$final = waitFor(function () use ($os, $order2, $token) {
    $o = orderStatus($os, $order2['id'], $token);

    return ($o['service_order']['status'] ?? '') === 'cancelada' ? $o : null;
}, 'OS cancelada após estorno', $timeout);
expect(true, 'execution-service recusou a reserva (estoque 1 < 5) → billing estornou → OS cancelada');
expect(str_contains((string) $final['service_order']['cancellation_reason'], 'Falha ao reservar pecas'), "motivo: {$final['service_order']['cancellation_reason']}");
[, $h2] = http('GET', "{$os}/api/service-order/{$order2['id']}/history", null, $token);
$steps = array_map(fn ($s) => "{$s['step']}:{$s['outcome']}", $h2['saga_steps']);
expect(in_array('compensation:refund_payment:compensated', $steps, true), 'passo de compensação registrado na saga');
echo '    passos: '.implode(' | ', $steps)."\n";
[, $bill] = http('GET', "{$billing}/api/billing/service-orders/{$order2['id']}", null, $token);
expect(($bill['payment']['status'] ?? '') === 'refunded', 'pagamento estornado no billing-service');
[, $stock] = http('GET', "{$execution}/api/catalog/items/{$item['id']}", null, $token);
expect(($stock['stock_quantity'] ?? null) == 1, 'estoque intacto (reserva atômica não baixou nada)');

echo "\nE2E OK — saga validada entre os 3 microsserviços.\n";
