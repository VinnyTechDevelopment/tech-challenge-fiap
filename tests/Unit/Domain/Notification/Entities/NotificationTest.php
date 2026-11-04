<?php

namespace Tests\Unit\Domain\Notification\Entities;

use App\Domain\Notification\Entities\Notification;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    private function makeNotification(): Notification
    {
        return new Notification(
            id: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            recipientId: 'customer-uuid',
            type: NotificationType::EMAIL,
            subject: 'Bem-vindo!',
            content: 'Olá, seja bem-vindo.',
            status: NotificationStatus::PENDING,
            createdAt: new DateTimeImmutable('2024-01-01 12:00:00'),
        );
    }

    public function test_getters_return_correct_values(): void
    {
        $notification = $this->makeNotification();

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $notification->getId());
        $this->assertSame('customer-uuid', $notification->getRecipientId());
        $this->assertSame(NotificationType::EMAIL, $notification->getType());
        $this->assertSame('Bem-vindo!', $notification->getSubject());
        $this->assertSame('Olá, seja bem-vindo.', $notification->getContent());
        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
        $this->assertNull($notification->getSentAt());
    }

    public function test_mark_as_sent_sets_status_and_sent_at(): void
    {
        $notification = $this->makeNotification();

        $before = new DateTimeImmutable;
        $notification->markAsSent();
        $after = new DateTimeImmutable;

        $this->assertSame(NotificationStatus::SENT, $notification->getStatus());
        $this->assertNotNull($notification->getSentAt());
        $this->assertGreaterThanOrEqual($before, $notification->getSentAt());
        $this->assertLessThanOrEqual($after, $notification->getSentAt());
    }

    public function test_mark_as_failed_sets_status_to_failed(): void
    {
        $notification = $this->makeNotification();

        $notification->markAsFailed();

        $this->assertSame(NotificationStatus::FAILED, $notification->getStatus());
        $this->assertNull($notification->getSentAt());
    }

    public function test_initial_status_is_pending(): void
    {
        $notification = $this->makeNotification();

        $this->assertSame(NotificationStatus::PENDING, $notification->getStatus());
    }
}
