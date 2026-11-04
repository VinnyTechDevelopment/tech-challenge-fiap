<?php

namespace Tests\Unit\Application\Notification\Handlers;

use App\Application\Notification\Handlers\SendWelcomeNotification;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Notification\Entities\Notification as NotificationEntity;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class NotifyCustomerWelcomeTest extends TestCase
{
    private MockInterface $repositoryMock;

    private MockInterface $serviceMock;

    private SendWelcomeNotification $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // Criamos Mocks das interfaces que o Handler depende
        $this->repositoryMock = Mockery::mock(NotificationRepositoryInterface::class);
        $this->serviceMock = Mockery::mock(NotificationServiceInterface::class);

        // Instanciamos o Handler com os Mocks
        $this->handler = new SendWelcomeNotification(
            $this->serviceMock,
            $this->repositoryMock
        );
    }

    public function test_should_process_welcome_notification_successfully(): void
    {
        $customer = new Customer(
            Str::uuid()->toString(),
            'Bad User',
            'error@example.com',
            '1234567890',
            '1234567890'
        );
        // ARRANGE (Preparar)
        $event = new CustomerCreated($customer);

        // Expectativa 1: Deve salvar a notificação como PENDING primeiro
        $this->repositoryMock
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (NotificationEntity $notification) {
                return $notification->getStatus() === NotificationStatus::PENDING;
            }));

        // Expectativa 2: Deve chamar o serviço de envio
        $this->serviceMock
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(NotificationEntity::class));

        // Expectativa 3: Deve salvar a notificação como SENT após o envio
        $this->repositoryMock
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (NotificationEntity $notification) {
                return $notification->getStatus() === NotificationStatus::SENT;
            }));

        // ACT (Agir)
        $this->handler->handle($event);

        // ASSERT (Verificar)
        // O Mockery já verifica as expectativas acima automaticamente
        $this->assertTrue(true);
    }

    public function test_should_mark_as_failed_if_service_throws_exception(): void
    {
        // ARRANGE
        $customer = new Customer(
            Str::uuid()->toString(),
            'Bad User',
            'error@example.com',
            '1234567890',
            '1234567890'
        );
        $event = new CustomerCreated($customer);

        // Primeira persistência (PENDING)
        $this->repositoryMock->shouldReceive('save')->once();

        // Simula uma falha no envio (Ex: API de e-mail fora do ar)
        $this->serviceMock
            ->shouldReceive('send')
            ->once()
            ->andThrow(new \Exception('Provider Offline'));

        // Expectativa: Deve salvar como FAILED
        $this->repositoryMock
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (NotificationEntity $notification) {
                return $notification->getStatus() === NotificationStatus::FAILED;
            }));

        // ACT
        $this->handler->handle($event);
        $this->assertTrue(true);
    }
}
