<?php

namespace App\Application\Notification\Handlers;

use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Notification\Entities\Notification as EntityNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Domain\ServiceOrder\Events\ServiceOrderStatusChanged;
use Illuminate\Support\Str;

class NotifyCustomerOnServiceOrderFinalized
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
        private NotificationRepositoryInterface $notificationRepository,
        private CustomerRepositoryInterface $customerRepository
    ) {}

    public function handle(ServiceOrderStatusChanged $event): void
    {
        if ($event->serviceOrder->status !== ServiceOrder::STATUS_FINALIZADA) {
            return;
        }

        $serviceOrder = $event->serviceOrder;
        $customer = $this->customerRepository->findById($serviceOrder->customerId);

        if (! $customer) {
            return;
        }

        $notification = new EntityNotification(
            Str::uuid()->toString(),
            $customer->email,
            NotificationType::EMAIL,
            'Sua ordem de serviço foi concluída',
            "Olá {$customer->name}, a sua ordem de serviço foi concluída e o veículo está pronto para retirada.",
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
