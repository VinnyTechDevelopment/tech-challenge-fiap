<?php

namespace App\Application\Notification\Handlers;

use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Notification\Entities\Notification as EntityNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use Illuminate\Support\Str;

class SendWelcomeNotification
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
        private NotificationRepositoryInterface $notificationRepository
    ) {}

    public function handle(CustomerCreated $event): void
    {
        $notification = new EntityNotification(
            Str::uuid()->toString(),
            $event->customer->id,
            NotificationType::EMAIL,
            'Bem-vindo ao nosso sistema '.$event->customer->name,
            'Ficamos felizes em ter você conosco!',
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
