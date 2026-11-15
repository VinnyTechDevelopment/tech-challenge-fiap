<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\ServiceOrderModel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ServiceOrderRepositoryEloquent implements ServiceOrderRepositoryInterface
{
    public function save(ServiceOrder $serviceOrder): void
    {
        ServiceOrderModel::create([
            'id' => $serviceOrder->id,
            'customer_id' => $serviceOrder->customerId,
            'vehicle_id' => $serviceOrder->vehicleId,
            'created_user_id' => Auth::id() ?? DB::table('users')->value('id'),
            'updated_user_id' => Auth::id() ?? DB::table('users')->value('id'),
        ] + $this->attributes($serviceOrder));
    }

    public function findById(string $id): ?ServiceOrder
    {
        $model = ServiceOrderModel::find($id);

        return $model ? $this->toEntity($model) : null;
    }

    public function findAll(): array
    {
        return ServiceOrderModel::with(['customer', 'vehicle', 'saga'])->get()->toArray();
    }

    public function paginate(int $page, int $perPage): array
    {
        return ServiceOrderModel::with(['customer', 'vehicle', 'saga'])
            ->whereNotIn('status', [ServiceOrder::STATUS_FINALIZADA, ServiceOrder::STATUS_ENTREGUE, ServiceOrder::STATUS_CANCELADA])
            ->orderByRaw("
                CASE status
                    WHEN 'em_execucao'          THEN 1
                    WHEN 'em_diagnostico'       THEN 2
                    WHEN 'aguardando_execucao'  THEN 3
                    WHEN 'aguardando_pagamento' THEN 4
                    WHEN 'aguardando_aprovacao' THEN 5
                    WHEN 'recebida'             THEN 6
                    ELSE 7
                END
            ")
            ->orderBy('created_at', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->toArray();
    }

    public function findByCustomerId(string $customerId): array
    {
        return ServiceOrderModel::with(['vehicle'])
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ServiceOrderModel $m) => [
                'id' => $m->id,
                'status' => $m->status,
                'vehicle' => $m->vehicle ? ['id' => $m->vehicle->id, 'plate' => $m->vehicle->plate ?? null] : null,
                'total_budget' => $m->total_budget,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function update(ServiceOrder $serviceOrder): void
    {
        $model = ServiceOrderModel::findOrFail($serviceOrder->id);
        $model->fill([
            'customer_id' => $serviceOrder->customerId,
            'vehicle_id' => $serviceOrder->vehicleId,
            'updated_user_id' => Auth::id() ?? $model->updated_user_id,
        ] + $this->attributes($serviceOrder))->save();
    }

    public function delete(string $id): void
    {
        ServiceOrderModel::where('id', $id)->delete();
    }

    public function recordStatusChange(string $serviceOrderId, ?string $from, string $to, string $source): void
    {
        DB::table('service_order_status_history')->insert([
            'service_order_id' => $serviceOrderId,
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'created_at' => now(),
        ]);
    }

    public function statusHistory(string $serviceOrderId): array
    {
        return DB::table('service_order_status_history')
            ->where('service_order_id', $serviceOrderId)
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'source', 'created_at'])
            ->map(static fn ($row) => (array) $row)
            ->all();
    }

    private function attributes(ServiceOrder $serviceOrder): array
    {
        $date = static fn (?string $value) => $value ? date('Y-m-d H:i:s', strtotime($value)) : null;

        return [
            'services' => $serviceOrder->services,
            'items' => $serviceOrder->items,
            'status' => $serviceOrder->status,
            'services_total' => $serviceOrder->servicesTotal,
            'parts_total' => $serviceOrder->itemsTotal,
            'total_budget' => $serviceOrder->totalBudget,
            'quote_sent_at' => $date($serviceOrder->quoteSentAt),
            'quote_approved_at' => $date($serviceOrder->quoteApprovedAt),
            'paid_at' => $date($serviceOrder->paidAt),
            'cancellation_reason' => $serviceOrder->cancellationReason,
        ];
    }

    private function toEntity(ServiceOrderModel $model): ServiceOrder
    {
        return new ServiceOrder(
            id: $model->id,
            customerId: $model->customer_id,
            vehicleId: $model->vehicle_id,
            services: $model->services ?? [],
            items: $model->items ?? [],
            status: $model->status,
            servicesTotal: (float) $model->services_total,
            itemsTotal: (float) $model->parts_total,
            totalBudget: (float) $model->total_budget,
            quoteSentAt: $model->quote_sent_at?->toIso8601String(),
            quoteApprovedAt: $model->quote_approved_at?->toIso8601String(),
            paidAt: $model->paid_at?->toIso8601String(),
            cancellationReason: $model->cancellation_reason,
        );
    }
}
