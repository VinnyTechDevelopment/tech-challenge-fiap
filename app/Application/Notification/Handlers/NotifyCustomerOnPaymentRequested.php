<?php

namespace App\Application\Notification\Handlers;

use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Notification\Entities\Notification as EntityNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Domain\ServiceOrder\Events\ServiceOrderPaymentRequested;
use Illuminate\Support\Str;

class NotifyCustomerOnPaymentRequested
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
        private NotificationRepositoryInterface $notificationRepository,
        private CustomerRepositoryInterface $customerRepository
    ) {}

    public function handle(ServiceOrderPaymentRequested $event): void
    {
        $serviceOrder = $event->serviceOrder;
        $customer = $this->customerRepository->findById($serviceOrder->customerId);

        if (! $customer) {
            return;
        }

        $notification = new EntityNotification(
            Str::uuid()->toString(),
            $customer->email,
            NotificationType::EMAIL,
            'Orçamento aprovado — conclua o pagamento',
            "Olá {$customer->name}, recebemos a aprovação do orçamento (R$ {$serviceOrder->totalBudget}). Conclua o pagamento pelo Mercado Pago para a sua ordem de serviço entrar na fila de execução:\n{$event->paymentUrl}",
            NotificationStatus::PENDING,
            new \DateTimeImmutable
        );

        $this->notificationRepository->save($notification);
        try {
            $this->notificationService->send($notification);
            $notification->markAsSent();
        } catch (\Throwable $th) {
            $notification->markAsFailed();
        }
        $this->notificationRepository->save($notification);
    }
}
