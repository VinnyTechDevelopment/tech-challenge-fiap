<?php

namespace App\Infrastructure\Notifications\Services;

use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Notification\Entities\Notification as NotificationEntity;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Infrastructure\Notifications\GenericNotification;
use Illuminate\Support\Facades\Notification;

class NotificationService implements NotificationServiceInterface
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository
    ) {}

    public function send(NotificationEntity $notification): void
    {
        $channel = $this->mapTypeToChannel($notification->getType());
        $destination = $this->getDestination($notification);
        Notification::route($channel, $destination)
            ->notify(new GenericNotification($notification));
    }

    private function mapTypeToChannel(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::EMAIL => 'mail',
            NotificationType::SMS => 'vonage',
            NotificationType::PUSH => 'fcm',
        };
    }

    private function getDestination(NotificationEntity $notification): string
    {
        $recipientId = $notification->getRecipientId();

        if (filter_var($recipientId, FILTER_VALIDATE_EMAIL) !== false) {
            return $recipientId;
        }

        $customer = $this->customerRepository->findById($recipientId);

        if (! $customer) {
            throw new \DomainException("Cliente '{$recipientId}' nao encontrado para envio de notificacao.");
        }

        return $customer->email;
    }
}
