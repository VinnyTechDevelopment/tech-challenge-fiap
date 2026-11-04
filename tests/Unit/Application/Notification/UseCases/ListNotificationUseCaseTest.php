<?php

namespace Tests\Unit\Application\Notification\UseCases;

use App\Application\Notification\DTOs\ListNotificationDTO;
use App\Application\Notification\UseCases\ListNotificationUseCase;
use App\Domain\Notification\Entities\Notification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class ListNotificationUseCaseTest extends TestCase
{
    private MockInterface $repository;

    private ListNotificationUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(NotificationRepositoryInterface::class);
        $this->useCase = new ListNotificationUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_list_notification()
    {
        $notifications = $this->makeNotifications();
        $this->repository->shouldReceive('paginate')->andReturn($notifications);
        $result = $this->useCase->execute(new ListNotificationDTO(page: 1, perPage: 10));
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Notification::class, $result[0]);
        $this->assertInstanceOf(Notification::class, $result[1]);
    }

    public function test_list_notification_with_no_notifications(): void
    {
        $this->repository->shouldReceive('paginate')->andReturn([]);
        $result = $this->useCase->execute(new ListNotificationDTO(page: 1, perPage: 10));
        $this->assertCount(0, $result);
    }

    private function makeNotification(string $id = 'test-notification-1'): Notification
    {
        return new Notification(
            id: $id,
            recipientId: 'test-recipient-id',
            type: NotificationType::EMAIL,
            subject: 'test',
            content: 'test',
            status: NotificationStatus::PENDING,
            createdAt: new DateTimeImmutable,
            sentAt: new DateTimeImmutable
        );
    }

    private function makeNotifications(): array
    {
        return [
            $this->makeNotification('test-notification-1'),
            $this->makeNotification('test-notification-2'),
        ];
    }
}
