<?php

namespace App\Application\Notification\Handlers;

use App\Domain\Notification\Entities\Notification as EntityNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Domain\ServiceOrder\Events\ServiceOrderCreated;
use App\Models\User;
use Illuminate\Support\Str;

class NotifyMechanicsOnServiceOrderCreated
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
        private NotificationRepositoryInterface $notificationRepository
    ) {}

    public function handle(ServiceOrderCreated $event): void
    {
        $serviceOrder = $event->serviceOrder;
        $mechanics = User::where('role', 'mecanico')->get();

        foreach ($mechanics as $mechanic) {
            $notification = new EntityNotification(
                Str::uuid()->toString(),
                $mechanic->email,
                NotificationType::EMAIL,
                'Nova ordem de serviço aberta',
                "Uma nova ordem de serviço foi aberta e aguarda atendimento. ID: {$serviceOrder->id}.",
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
}
