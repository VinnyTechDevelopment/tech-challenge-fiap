<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Application\Saga\ServiceOrderSagaOrchestrator;
use App\Domain\Saga\Entities\ServiceOrderSaga;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;

/**
 * Cancelamento pelo atendimento. Não cancela "na hora": dispara as
 * compensações da saga; a OS vira "cancelada" quando os participantes
 * confirmarem (estorno, devolução de peças, cancelamento do orçamento).
 */
class CancelServiceOrderUseCase
{
    public function __construct(
        private ServiceOrderRepositoryInterface $orders,
        private ServiceOrderSagaOrchestrator $orchestrator,
    ) {}

    public function execute(string $serviceOrderId, string $reason): ServiceOrderSaga
    {
        $order = $this->orders->findById($serviceOrderId)
            ?? throw new \DomainException('Ordem de servico nao encontrada');

        if ($order->isFinal() || ! $order->canTransitionTo($order::STATUS_CANCELADA)) {
            throw new \DomainException("Ordem de servico em '{$order->status}' nao pode ser cancelada.");
        }

        return $this->orchestrator->cancelByStaff($serviceOrderId, $reason);
    }
}
