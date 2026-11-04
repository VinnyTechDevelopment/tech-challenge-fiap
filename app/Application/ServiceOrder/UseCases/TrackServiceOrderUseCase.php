<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Domain\Saga\Interfaces\ServiceOrderSagaRepositoryInterface;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;

/**
 * Consulta de status e histórico: transições de status da OS e os passos
 * da saga (inclusive compensações).
 */
class TrackServiceOrderUseCase
{
    public function __construct(
        private ServiceOrderRepositoryInterface $orders,
        private ServiceOrderSagaRepositoryInterface $sagas,
    ) {}

    public function execute(string $serviceOrderId): array
    {
        $order = $this->orders->findById($serviceOrderId)
            ?? throw new \DomainException('Ordem de servico nao encontrada');

        $saga = $this->sagas->findByServiceOrderId($order->id);

        return [
            'service_order_id' => $order->id,
            'status' => $order->status,
            'history' => $this->orders->statusHistory($order->id),
            'saga' => $saga?->toArray(),
            'saga_steps' => $saga ? $this->sagas->steps($saga->id) : [],
        ];
    }
}
