<?php

namespace App\Domain\ServiceOrder\Entities;

use Illuminate\Support\Str;

/**
 * Ordem de serviço. Desde a Fase 4 o status avança pelas respostas da saga
 * (billing-service e execution-service), não mais por edição direta:
 *
 *  recebida -> aguardando_aprovacao -> aguardando_pagamento -> aguardando_execucao
 *           -> em_diagnostico -> em_execucao -> finalizada -> entregue
 *  (qualquer status antes de finalizada) -> cancelada   (compensação da saga)
 */
class ServiceOrder
{
    public const STATUS_RECEBIDA = 'recebida';

    public const STATUS_AGUARDANDO_APROVACAO = 'aguardando_aprovacao';

    public const STATUS_AGUARDANDO_PAGAMENTO = 'aguardando_pagamento';

    public const STATUS_AGUARDANDO_EXECUCAO = 'aguardando_execucao';

    public const STATUS_EM_DIAGNOSTICO = 'em_diagnostico';

    public const STATUS_EM_EXECUCAO = 'em_execucao';

    public const STATUS_FINALIZADA = 'finalizada';

    public const STATUS_ENTREGUE = 'entregue';

    public const STATUS_CANCELADA = 'cancelada';

    /**
     * Transições permitidas (status atual => próximos possíveis).
     */
    private const TRANSITIONS = [
        self::STATUS_RECEBIDA => [self::STATUS_AGUARDANDO_APROVACAO, self::STATUS_CANCELADA],
        self::STATUS_AGUARDANDO_APROVACAO => [self::STATUS_AGUARDANDO_PAGAMENTO, self::STATUS_CANCELADA],
        self::STATUS_AGUARDANDO_PAGAMENTO => [self::STATUS_AGUARDANDO_EXECUCAO, self::STATUS_CANCELADA],
        self::STATUS_AGUARDANDO_EXECUCAO => [self::STATUS_EM_DIAGNOSTICO, self::STATUS_CANCELADA],
        self::STATUS_EM_DIAGNOSTICO => [self::STATUS_EM_EXECUCAO, self::STATUS_CANCELADA],
        self::STATUS_EM_EXECUCAO => [self::STATUS_FINALIZADA, self::STATUS_CANCELADA],
        self::STATUS_FINALIZADA => [self::STATUS_ENTREGUE],
        self::STATUS_ENTREGUE => [],
        self::STATUS_CANCELADA => [],
    ];

    /**
     * @param  array<int, array{service_id: string, name: string, quantity: float, unit_price: float}>  $services
     * @param  array<int, array{item_id: string, name: string, quantity: float, unit_price: float}>  $items
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $vehicleId,
        public array $services,
        public array $items,
        public string $status,
        public float $servicesTotal,
        public float $itemsTotal,
        public float $totalBudget,
        public ?string $quoteSentAt = null,
        public ?string $quoteApprovedAt = null,
        public ?string $paidAt = null,
        public ?string $cancellationReason = null,
    ) {
        $this->assertStatus($status);
    }

    public static function create(
        string $customerId,
        string $vehicleId,
        array $services,
        array $items
    ): self {
        if ($services === []) {
            throw new \DomainException('A ordem de servico precisa de ao menos um servico.');
        }

        $servicesTotal = self::calculateItemsTotal($services);
        $itemsTotal = self::calculateItemsTotal($items);

        return new self(
            id: Str::uuid()->toString(),
            customerId: $customerId,
            vehicleId: $vehicleId,
            services: array_values($services),
            items: array_values($items),
            status: self::STATUS_RECEBIDA,
            servicesTotal: $servicesTotal,
            itemsTotal: $itemsTotal,
            totalBudget: round($servicesTotal + $itemsTotal, 2),
        );
    }

    public function quoteSent(string $now): void
    {
        $this->transitionTo(self::STATUS_AGUARDANDO_APROVACAO);
        $this->quoteSentAt = $now;
    }

    public function quoteApproved(string $now): void
    {
        $this->transitionTo(self::STATUS_AGUARDANDO_PAGAMENTO);
        $this->quoteApprovedAt = $now;
    }

    /**
     * Pagamento confirmado: registra a data, o status muda quando a OS
     * efetivamente entra na fila de execução.
     */
    public function paymentConfirmed(string $now): void
    {
        if ($this->status !== self::STATUS_AGUARDANDO_PAGAMENTO) {
            throw new \DomainException("OS em '{$this->status}' nao esta aguardando pagamento.");
        }

        $this->paidAt = $now;
    }

    public function changeStatus(string $status): void
    {
        $this->transitionTo($status);
    }

    public function cancel(string $reason): void
    {
        $this->transitionTo(self::STATUS_CANCELADA);
        $this->cancellationReason = $reason;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status], true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_ENTREGUE, self::STATUS_CANCELADA], true);
    }

    public static function allowedStatuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    private function transitionTo(string $status): void
    {
        $this->assertStatus($status);

        if (! $this->canTransitionTo($status)) {
            throw new \DomainException("Transicao de status invalida: {$this->status} -> {$status}.");
        }

        $this->status = $status;
    }

    private function assertStatus(string $status): void
    {
        if (! array_key_exists($status, self::TRANSITIONS)) {
            throw new \InvalidArgumentException('Status da OS invalido');
        }
    }

    private static function calculateItemsTotal(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $total += $quantity * $unitPrice;
        }

        return round($total, 2);
    }
}
