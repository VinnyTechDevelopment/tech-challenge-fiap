<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Saga\Entities\ServiceOrderSaga;
use App\Domain\Saga\Interfaces\ServiceOrderSagaRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\ServiceOrderSagaModel;
use Illuminate\Support\Facades\DB;

class ServiceOrderSagaRepositoryEloquent implements ServiceOrderSagaRepositoryInterface
{
    public function save(ServiceOrderSaga $saga): void
    {
        (new ServiceOrderSagaModel)->forceFill(['id' => $saga->id] + $this->attributes($saga))->save();
    }

    public function update(ServiceOrderSaga $saga): void
    {
        ServiceOrderSagaModel::findOrFail($saga->id)->fill($this->attributes($saga))->save();
    }

    public function findForUpdate(string $sagaId): ?ServiceOrderSaga
    {
        return $this->toEntity(ServiceOrderSagaModel::query()->whereKey($sagaId)->lockForUpdate()->first());
    }

    public function findByServiceOrderId(string $serviceOrderId): ?ServiceOrderSaga
    {
        return $this->toEntity(ServiceOrderSagaModel::query()->where('service_order_id', $serviceOrderId)->first());
    }

    public function findExpired(string $now, int $limit = 100): array
    {
        return ServiceOrderSagaModel::query()
            ->whereIn('state', [ServiceOrderSaga::AWAITING_APPROVAL, ServiceOrderSaga::AWAITING_PAYMENT])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', date('Y-m-d H:i:s', strtotime($now)))
            ->orderBy('deadline_at')
            ->limit($limit)
            ->get()
            ->map(fn (ServiceOrderSagaModel $m) => $this->toEntity($m))
            ->all();
    }

    public function recordStep(string $sagaId, string $serviceOrderId, string $step, string $outcome, ?string $messageType, array $details = []): void
    {
        DB::table('saga_steps')->insert([
            'saga_id' => $sagaId,
            'service_order_id' => $serviceOrderId,
            'step' => $step,
            'outcome' => $outcome,
            'message_type' => $messageType,
            'details' => json_encode(array_filter($details, static fn ($v) => $v !== null)),
            'created_at' => now(),
        ]);
    }

    public function steps(string $sagaId): array
    {
        return DB::table('saga_steps')
            ->where('saga_id', $sagaId)
            ->orderBy('id')
            ->get()
            ->map(static fn ($row) => [
                'step' => $row->step,
                'outcome' => $row->outcome,
                'message_type' => $row->message_type,
                'details' => json_decode((string) $row->details, true) ?: [],
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    private function attributes(ServiceOrderSaga $saga): array
    {
        return [
            'service_order_id' => $saga->serviceOrderId,
            'state' => $saga->state,
            'quote_id' => $saga->quoteId,
            'payment_id' => $saga->paymentId,
            'approval_url' => $saga->approvalUrl,
            'reject_url' => $saga->rejectUrl,
            'payment_url' => $saga->paymentUrl,
            'failure_reason' => $saga->failureReason,
            'pending_compensations' => $saga->pendingCompensations,
            'deadline_at' => $saga->deadlineAt ? date('Y-m-d H:i:s', strtotime($saga->deadlineAt)) : null,
        ];
    }

    private function toEntity(?ServiceOrderSagaModel $model): ?ServiceOrderSaga
    {
        if (! $model) {
            return null;
        }

        return new ServiceOrderSaga(
            id: $model->id,
            serviceOrderId: $model->service_order_id,
            state: $model->state,
            quoteId: $model->quote_id,
            paymentId: $model->payment_id,
            approvalUrl: $model->approval_url,
            rejectUrl: $model->reject_url,
            paymentUrl: $model->payment_url,
            failureReason: $model->failure_reason,
            pendingCompensations: $model->pending_compensations ?? [],
            deadlineAt: $model->deadline_at?->toIso8601String(),
        );
    }
}
