<?php

namespace Tests\Unit\Application\Notification\UseCases;

use App\Application\Notification\DTOs\ShowNotificationDTO;
use App\Application\Notification\UseCases\ShowNotificationUseCase;
use App\Domain\Notification\Entities\Notification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class ShowNotificationUseCaseTest extends TestCase
{
    private MockInterface $repository;

    private ShowNotificationUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(NotificationRepositoryInterface::class);
        $this->useCase = new ShowNotificationUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_show_notification()
    {
        $notification = new Notification(
            id: 'test',
            recipientId: 'test-recipient-id',
            type: NotificationType::EMAIL,
            subject: 'test',
            content: 'test',
            status: NotificationStatus::PENDING,
            createdAt: new DateTimeImmutable,
            sentAt: new DateTimeImmutable
        );

        $this->repository->shouldReceive('findById')->andReturn($notification);

        $result = $this->useCase->execute(new ShowNotificationDTO(id: 'test'));

        $this->assertInstanceOf(Notification::class, $result);
    }

    public function test_show_notification_with_invalid_id(): void
    {
        $this->repository->shouldReceive('findById')->andReturnNull();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Notificação não encontrada');
        $this->useCase->execute(new ShowNotificationDTO(id: 'invalid'));
    }
}
