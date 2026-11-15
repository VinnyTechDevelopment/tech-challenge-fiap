<?php

namespace App\Domain\Saga\Interfaces;

use App\Domain\Saga\Entities\ServiceOrderSaga;

interface ServiceOrderSagaRepositoryInterface
{
    public function save(ServiceOrderSaga $saga): void;

    public function update(ServiceOrderSaga $saga): void;

    /**
     * Carrega a saga com lock pessimista (SELECT ... FOR UPDATE) para que
     * duas respostas concorrentes da mesma saga não se atropelem.
     */
    public function findForUpdate(string $sagaId): ?ServiceOrderSaga;

    public function findByServiceOrderId(string $serviceOrderId): ?ServiceOrderSaga;

    /**
     * @return ServiceOrderSaga[] sagas aguardando cliente com prazo vencido
     */
    public function findExpired(string $now, int $limit = 100): array;

    public function recordStep(string $sagaId, string $serviceOrderId, string $step, string $outcome, ?string $messageType, array $details = []): void;

    /**
     * @return array<int, array{step: string, outcome: string, message_type: ?string, details: array, created_at: string}>
     */
    public function steps(string $sagaId): array;
}
