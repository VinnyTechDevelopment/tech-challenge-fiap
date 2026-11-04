<?php

namespace Tests\Behat;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageTypes;
use App\Models\User;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\SagaTestHarness;
use Tests\TestCase;

/**
 * Passos do BDD da saga. Herda o TestCase do Laravel para usar a mesma
 * infraestrutura de teste do PHPUnit (banco de testes, actingAs, getJson...).
 * Os participantes (billing/execution) são simulados pelo SagaTestHarness,
 * que publica as respostas na fila os-saga-replies e roda o consumer real.
 */
class SagaOrdemServicoContext extends TestCase implements Context
{
    use RefreshDatabase;

    private SagaTestHarness $saga;

    private User $staff;

    /** @var array{customer_id: string, vehicle_id: string} */
    private array $customer;

    private string $orderId;

    private string $sagaId;

    /** @var Message[] comandos enviados pelo orquestrador, em ordem */
    private array $commands = [];

    /** Resposta => comando a que ela responde */
    private const REPLY_TO = [
        MessageTypes::BILLING_QUOTE_CREATED => MessageTypes::BILLING_CREATE_QUOTE,
        MessageTypes::BILLING_QUOTE_CANCELLED => MessageTypes::BILLING_CANCEL_QUOTE,
        MessageTypes::BILLING_PAYMENT_REFUNDED => MessageTypes::BILLING_REFUND_PAYMENT,
        MessageTypes::EXECUTION_QUEUED => MessageTypes::EXECUTION_RESERVE_AND_ENQUEUE,
        MessageTypes::EXECUTION_RESERVATION_FAILED => MessageTypes::EXECUTION_RESERVE_AND_ENQUEUE,
        MessageTypes::EXECUTION_CANCELLED => MessageTypes::EXECUTION_CANCEL,
    ];

    #[BeforeScenario]
    public function prepare(): void
    {
        $this->setUp();
        $this->saga = new SagaTestHarness($this->app);
        $this->commands = [];
    }

    #[AfterScenario]
    public function cleanup(): void
    {
        // tearDown() do PHPUnit depende do runner do PHPUnit; aqui basta
        // desfazer a transação do RefreshDatabase e descartar a aplicação.
        $this->callBeforeApplicationDestroyedCallbacks();
        $this->app->flush();
        $this->app = null;
    }

    #[Given('que estou autenticado como atendente')]
    public function atendente(): void
    {
        $this->staff = $this->saga->givenStaff();
    }

    #[Given('que existe um cliente com um veículo cadastrado')]
    public function clienteComVeiculo(): void
    {
        $this->customer = $this->saga->givenCustomerWithVehicle($this->staff);
    }

    #[When('eu abro uma OS com o serviço :service e :quantity unidades da peça :item')]
    public function abrirOs(string $service, string $quantity, string $item): void
    {
        $response = $this->actingAs($this->staff, 'api')->postJson('/api/service-order', $this->customer + [
            'services' => [['service_id' => $service, 'quantity' => 1]],
            'items' => [['item_id' => $item, 'quantity' => (float) $quantity]],
        ]);

        Assert::assertSame(202, $response->status(), (string) $response->getContent());
        $this->orderId = $response->json('service_order.id');
        $this->sagaId = $response->json('saga.id');
    }

    #[Given('uma OS com pagamento confirmado')]
    public function osPaga(): void
    {
        $this->abrirOs('svc-revisao', '2', 'item-filtro');
        $this->participanteResponde('billing', MessageTypes::BILLING_QUOTE_CREATED);
        $this->clienteAprova();
        $this->pagamentoConfirmado();
    }

    #[When('o :service-service responder :type')]
    public function participanteResponde(string $service, string $type): void
    {
        $command = $this->lastCommand(self::REPLY_TO[$type] ?? throw new \InvalidArgumentException("Resposta sem comando mapeado: {$type}"));

        $this->saga->reply($command, $type, [
            'quote_id' => 'quote-bdd',
            'approval_url' => 'https://api.exemplo/billing/approve',
            'reject_url' => 'https://api.exemplo/billing/reject',
            'reason' => $type === MessageTypes::EXECUTION_RESERVATION_FAILED ? 'Insufficient stock' : null,
            'amount' => 391,
        ]);
    }

    #[When('o cliente aprovar o orçamento')]
    public function clienteAprova(): void
    {
        $this->event(MessageTypes::BILLING_QUOTE_APPROVED, ['payment_id' => 'pay-bdd', 'payment_url' => 'https://mercadopago/checkout']);
    }

    #[When('o cliente recusar o orçamento')]
    public function clienteRecusa(): void
    {
        $this->event(MessageTypes::BILLING_QUOTE_REJECTED);
    }

    #[When('o Mercado Pago confirmar o pagamento')]
    public function pagamentoConfirmado(): void
    {
        $this->event(MessageTypes::BILLING_PAYMENT_CONFIRMED, ['payment_id' => 'pay-bdd', 'amount' => 391]);
    }

    #[When('o Mercado Pago recusar o pagamento')]
    public function pagamentoRecusado(): void
    {
        $this->event(MessageTypes::BILLING_PAYMENT_FAILED, ['reason' => 'cc_rejected_insufficient_amount']);
    }

    #[When('o mecânico iniciar o diagnóstico')]
    public function diagnostico(): void
    {
        $this->event(MessageTypes::EXECUTION_STATUS_CHANGED, ['status' => 'in_diagnosis']);
    }

    #[When('o mecânico iniciar o reparo')]
    public function reparo(): void
    {
        $this->event(MessageTypes::EXECUTION_STATUS_CHANGED, ['status' => 'in_repair']);
    }

    #[When('o mecânico finalizar a execução')]
    public function finalizar(): void
    {
        $this->event(MessageTypes::EXECUTION_FINISHED);
    }

    #[When('o atendente registrar a entrega do veículo')]
    public function entrega(): void
    {
        $this->actingAs($this->staff, 'api')
            ->patchJson("/api/service-order/{$this->orderId}/status", ['status' => 'entregue'])
            ->assertOk();
    }

    #[When('o atendente cancelar a OS informando :reason')]
    public function cancelar(string $reason): void
    {
        $this->actingAs($this->staff, 'api')
            ->postJson("/api/service-order/{$this->orderId}/cancel", ['reason' => $reason])
            ->assertStatus(202);
    }

    #[Then('a OS deve estar com status :status')]
    public function statusDaOs(string $status): void
    {
        Assert::assertSame($status, DB::table('service_orders')->where('id', $this->orderId)->value('status'));
    }

    #[Then('a saga deve estar no estado :state')]
    public function estadoDaSaga(string $state): void
    {
        Assert::assertSame($state, DB::table('service_order_sagas')->where('id', $this->sagaId)->value('state'));
    }

    #[Then('o os-service deve ter enviado o comando :type ao :service-service')]
    public function comandoEnviado(string $type, string $service): void
    {
        $command = $this->lastCommand($type);
        $expectedQueue = "{$service}-commands";

        Assert::assertSame($this->sagaId, $command->sagaId);
        Assert::assertSame($this->orderId, $command->get('service_order_id'));
        Assert::assertContains($expectedQueue, [MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::QUEUE_EXECUTION_COMMANDS]);
        Assert::assertStringStartsWith($service.'.', $type);
    }

    #[Then('a saga deve registrar a compensação :step')]
    public function compensacaoRegistrada(string $step): void
    {
        Assert::assertTrue(
            DB::table('saga_steps')->where('saga_id', $this->sagaId)->where('step', $step)->where('outcome', 'compensated')->exists(),
            "Compensacao '{$step}' nao registrada."
        );
    }

    #[Then('o histórico da OS deve conter os status :statuses')]
    public function historico(string $statuses): void
    {
        $history = $this->actingAs($this->staff, 'api')->getJson("/api/service-order/{$this->orderId}/history")->json('history');

        Assert::assertSame(array_map('trim', explode(',', $statuses)), array_column($history, 'to_status'));
    }

    private function event(string $type, array $payload = []): void
    {
        $this->saga->event($this->sagaId, $this->orderId, $type, $payload);
    }

    private function lastCommand(string $type): Message
    {
        foreach ([MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::QUEUE_EXECUTION_COMMANDS] as $queue) {
            array_push($this->commands, ...$this->saga->takeCommands($queue));
        }

        $matching = array_values(array_filter($this->commands, fn (Message $m) => $m->type === $type));
        Assert::assertNotEmpty($matching, "Comando {$type} nao foi enviado pelo orquestrador.");

        return end($matching);
    }
}
