<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Application\ServiceOrder\DTOs\UpdateServiceOrderStatusDTO;
use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Domain\ServiceOrder\Events\ServiceOrderStatusChanged;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atualização manual de status pelo atendimento. Desde a Fase 4 os demais
 * status são conduzidos pela saga; manualmente só se registra a entrega do
 * veículo (finalizada -> entregue).
 */
class UpdateServiceOrderStatusUseCase
{
    public const MANUAL_STATUSES = [ServiceOrder::STATUS_ENTREGUE];

    public function __construct(
        private ServiceOrderRepositoryInterface $repository
    ) {}

    public function execute(UpdateServiceOrderStatusDTO $dto): ServiceOrder
    {
        $serviceOrder = $this->repository->findById($dto->id);

        if (! $serviceOrder) {
            throw new \DomainException('Ordem de servico nao encontrada');
        }

        if (! in_array($dto->status, self::MANUAL_STATUSES, true)) {
            throw new \DomainException(
                "Status '{$dto->status}' e controlado pela saga (billing/execution). Manualmente so e permitido: ".implode(', ', self::MANUAL_STATUSES).'.'
            );
        }

        $oldStatus = $serviceOrder->status;
        $serviceOrder->changeStatus($dto->status);

        DB::transaction(function () use ($serviceOrder, $oldStatus): void {
            $this->repository->update($serviceOrder);
            $this->repository->recordStatusChange($serviceOrder->id, $oldStatus, $serviceOrder->status, 'atendimento');
        });

        event(new ServiceOrderStatusChanged($serviceOrder, $oldStatus));

        return $serviceOrder;
    }
}
