<?php

namespace App\Presentation\Http\Controllers;

use App\Application\ServiceOrder\DTOs\CreateServiceOrderDTO;
use App\Application\ServiceOrder\DTOs\DeleteServiceOrderDTO;
use App\Application\ServiceOrder\DTOs\ListServiceOrderDTO;
use App\Application\ServiceOrder\DTOs\ShowServiceOrderDTO;
use App\Application\ServiceOrder\DTOs\UpdateServiceOrderStatusDTO;
use App\Application\ServiceOrder\UseCases\CancelServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\CreateServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\DeleteServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\ListServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\ShowServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\TrackServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\UpdateServiceOrderStatusUseCase;
use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Presentation\Http\Requests\CreateServiceOrderRequest;
use App\Presentation\Http\Requests\ListServiceOrderRequest;
use App\Presentation\Http\Requests\UpdateServiceOrderStatusRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceOrderController
{
    public function store(CreateServiceOrderRequest $request, CreateServiceOrderUseCase $useCase): JsonResponse
    {
        ['order' => $order, 'saga' => $saga] = $useCase->execute(new CreateServiceOrderDTO(
            vehicleId: $request->input('vehicle_id'),
            customerId: $request->input('customer_id'),
            services: $request->input('services', []),
            items: $request->input('items', []),
        ));

        return response()->json([
            'service_order' => $this->present($order),
            'saga' => $saga->toArray(),
            'message' => 'Ordem de servico aberta. Orcamento sendo gerado pelo billing-service.',
        ], 202);
    }

    public function list(ListServiceOrderRequest $request, ListServiceOrderUseCase $useCase): JsonResponse
    {
        return response()->json($useCase->execute(new ListServiceOrderDTO(
            page: (int) $request->input('page', 1),
            perPage: (int) $request->input('perPage', 10)
        )));
    }

    public function show(string $id, ShowServiceOrderUseCase $useCase, TrackServiceOrderUseCase $tracking): JsonResponse
    {
        $order = $useCase->execute(new ShowServiceOrderDTO(id: $id));
        $track = $tracking->execute($id);

        return response()->json([
            'service_order' => $this->present($order),
            'saga' => $track['saga'],
        ]);
    }

    public function history(string $id, TrackServiceOrderUseCase $useCase): JsonResponse
    {
        return response()->json($useCase->execute($id));
    }

    public function updateStatus(UpdateServiceOrderStatusRequest $request, UpdateServiceOrderStatusUseCase $useCase): JsonResponse
    {
        $order = $useCase->execute(new UpdateServiceOrderStatusDTO(
            id: $request->route('id'),
            status: $request->input('status')
        ));

        return response()->json([
            'service_order' => $this->present($order),
            'message' => 'Status da ordem de servico atualizado com sucesso',
        ]);
    }

    public function cancel(string $id, Request $request, CancelServiceOrderUseCase $useCase): JsonResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:300'])['reason'];
        $saga = $useCase->execute($id, $reason);

        return response()->json([
            'saga' => $saga->toArray(),
            'message' => 'Cancelamento iniciado: compensacoes enviadas aos servicos envolvidos.',
        ], 202);
    }

    public function destroy(string $id, DeleteServiceOrderUseCase $useCase)
    {
        $useCase->execute(new DeleteServiceOrderDTO(id: $id));

        return response()->noContent();
    }

    private function present(ServiceOrder $order): array
    {
        return [
            'id' => $order->id,
            'customer_id' => $order->customerId,
            'vehicle_id' => $order->vehicleId,
            'services' => $order->services,
            'items' => $order->items,
            'status' => $order->status,
            'services_total' => $order->servicesTotal,
            'items_total' => $order->itemsTotal,
            'total_budget' => $order->totalBudget,
            'quote_sent_at' => $order->quoteSentAt,
            'quote_approved_at' => $order->quoteApprovedAt,
            'paid_at' => $order->paidAt,
            'cancellation_reason' => $order->cancellationReason,
        ];
    }
}
