<?php

namespace Tests\Unit\Infrastructure\Notifications\Services;

use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Notification\Entities\Notification as NotificationEntity;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Infrastructure\Notifications\GenericNotification;
use App\Infrastructure\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    public function test_send_accepts_email_as_recipient_id(): void
    {
        Notification::fake();

        $customerRepository = $this->mock(CustomerRepositoryInterface::class);
        $customerRepository->shouldNotReceive('findById');

        $service = new NotificationService($customerRepository);

        $notification = new NotificationEntity(
            id: 'notif-1',
            recipientId: 'direct.email@example.com',
            type: NotificationType::EMAIL,
            subject: 'Assunto',
            content: 'Conteudo',
            status: NotificationStatus::PENDING,
            createdAt: new \DateTimeImmutable
        );

        $service->send($notification);

        Notification::assertSentOnDemand(GenericNotification::class);
    }
}
