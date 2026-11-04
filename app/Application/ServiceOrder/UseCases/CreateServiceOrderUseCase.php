<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Application\Saga\ServiceOrderSagaOrchestrator;
use App\Application\ServiceOrder\DTOs\CreateServiceOrderDTO;
use App\Application\ServiceOrder\Ports\CatalogClientInterface;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Saga\Entities\ServiceOrderSaga;
use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Domain\ServiceOrder\Events\ServiceOrderCreated;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Abertura da OS = início da saga. A OS e o primeiro comando (CreateQuote,
 * via outbox) são gravados na mesma transação.
 */
class CreateServiceOrderUseCase
{
    public function __construct(
        private ServiceOrderRepositoryInterface $serviceOrderRepository,
        private CustomerRepositoryInterface $customerRepository,
        private VehicleRepositoryInterface $vehicleRepository,
        private CatalogClientInterface $catalog,
        private ServiceOrderSagaOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{order: ServiceOrder, saga: ServiceOrderSaga}
     */
    public function execute(CreateServiceOrderDTO $dto): array
    {
        $customer = $this->customerRepository->findById($dto->customerId)
            ?? throw new \DomainException("Cliente '{$dto->customerId}' nao encontrado.");

        $vehicle = $this->vehicleRepository->findById($dto->vehicleId)
            ?? throw new \DomainException("Veiculo '{$dto->vehicleId}' nao encontrado.");

        // Chamada síncrona ao execution-service: nome e preço vigentes.
        $priced = $this->catalog->lookup($dto->services, $dto->items);

        $order = ServiceOrder::create(
            customerId: $customer->id,
            vehicleId: $vehicle->id,
            services: array_map(static fn (array $s) => [
                'service_id' => (string) $s['service_id'],
                'name' => (string) $s['name'],
                'quantity' => (float) $s['quantity'],
                'unit_price' => (float) $s['unit_price'],
            ], $priced['services']),
            items: array_map(static fn (array $i) => [
                'item_id' => (string) $i['item_id'],
                'name' => (string) $i['name'],
                'quantity' => (float) $i['quantity'],
                'unit_price' => (float) $i['unit_price'],
            ], $priced['items']),
        );

        $saga = DB::transaction(function () use ($order): ServiceOrderSaga {
            $this->serviceOrderRepository->save($order);
            $this->serviceOrderRepository->recordStatusChange($order->id, null, $order->status, 'atendimento');

            return $this->orchestrator->start($order);
        });

        event(new ServiceOrderCreated($order));

        return ['order' => $order, 'saga' => $saga];
    }
}
