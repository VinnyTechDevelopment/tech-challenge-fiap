<?php

namespace Tests\Unit\Application\Notification\Handlers;

use App\Application\Notification\Handlers\SendServiceOrderStatusNotification;
use App\Domain\Notification\Entities\Notification as EntitiesNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Domain\ServiceOrder\Events\ServiceOrderStatusChanged;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class NotifyServiceOrderStatusTest extends TestCase
{
    private NotificationRepositoryInterface&MockInterface $repositoryMock;

    private NotificationServiceInterface&MockInterface $serviceMock;

    private SendServiceOrderStatusNotification $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryMock = Mockery::mock(NotificationRepositoryInterface::class);
        $this->serviceMock = Mockery::mock(NotificationServiceInterface::class);

        $this->handler = new SendServiceOrderStatusNotification(
            $this->serviceMock,
            $this->repositoryMock
        );
    }

    public function test_should_notify_customer_when_os_status_changes(): void
    {
        // ARRANGE
        $serviceOrder = new ServiceOrder(
            id: 'OS-123',
            customerId: '123',
            vehicleId: '456',
            services: [],
            items: [],
            status: ServiceOrder::STATUS_RECEBIDA,
            servicesTotal: 0,
            itemsTotal: 0,
            totalBudget: 0,
            quoteSentAt: null,
            quoteApprovedAt: null
        );
        $event = new ServiceOrderStatusChanged(
            serviceOrder: $serviceOrder,
            oldStatus: ServiceOrder::STATUS_FINALIZADA
        );

        // Expectativa: Salvar como PENDING antes de enviar
        $this->repositoryMock
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn (EntitiesNotification $n) => $n->getStatus() === NotificationStatus::PENDING));

        // Expectativa: Chamar o serviço de envio
        $this->serviceMock
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(EntitiesNotification::class));

        // Expectativa: Atualizar para SENT após sucesso
        $this->repositoryMock
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn (EntitiesNotification $n) => $n->getStatus() === NotificationStatus::SENT));

        // ACT
        $this->handler->handle($event);

        // ASSERT (implícito pelas expectativas do Mockery)
        $this->assertTrue(true);
    }

    public function test_should_record_failure_when_sending_fails(): void
    {
        // ARRANGE
        $event = new ServiceOrderStatusChanged(new ServiceOrder(
            id: 'OS-123',
            customerId: '123',
            vehicleId: '456',
            services: [],
            items: [],
            status: ServiceOrder::STATUS_FINALIZADA,
            servicesTotal: 0,
            itemsTotal: 0,
            totalBudget: 0,
            quoteSentAt: null,
            quoteApprovedAt: null
        ), ServiceOrder::STATUS_FINALIZADA);

        $this->repositoryMock->shouldReceive('save')->once(); // Pendente

        // Simula falha na infraestrutura (ex: e-mail indisponível)
        $this->serviceMock
            ->shouldReceive('send')
            ->andThrow(new \Exception('SMTP Error'));

        // Expectativa: Salvar como FAILED no banco de histórico
        $this->repositoryMock
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn (EntitiesNotification $n) => $n->getStatus() === NotificationStatus::FAILED));

        // ACT
        $this->handler->handle($event);
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
