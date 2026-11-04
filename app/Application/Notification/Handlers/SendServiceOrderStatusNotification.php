<?php

namespace App\Application\Notification\Handlers;

use App\Domain\Notification\Entities\Notification as EntityNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Domain\ServiceOrder\Events\ServiceOrderStatusChanged;
use Illuminate\Support\Str;

class SendServiceOrderStatusNotification
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
        private NotificationRepositoryInterface $notificationRepository
    ) {}

    public function handle(ServiceOrderStatusChanged $event)
    {
        $serviceOrder = $event->serviceOrder;
        $oldStatus = $event->oldStatus;
        $newStatus = $event->serviceOrder->status;
        $notification = new EntityNotification(
            Str::uuid()->toString(),
            $serviceOrder->customerId,
            NotificationType::EMAIL,
            'Status da ordem de servico alterado',
            'O status da ordem de servico foi alterado para '.$newStatus,
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
