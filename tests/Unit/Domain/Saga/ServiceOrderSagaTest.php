<?php

namespace Tests\Unit\Domain\Saga;

use App\Domain\Saga\Entities\ServiceOrderSaga;
use PHPUnit\Framework\TestCase;

class ServiceOrderSagaTest extends TestCase
{
    public function test_happy_path_state_machine(): void
    {
        $saga = ServiceOrderSaga::start('os-1');
        $this->assertSame(ServiceOrderSaga::QUOTE_REQUESTED, $saga->state);

        $saga->quoteCreated('q1', 'http://a', 'http://r', '2026-11-06T10:00:00+00:00');
        $this->assertSame(ServiceOrderSaga::AWAITING_APPROVAL, $saga->state);
        $this->assertTrue($saga->isExpired('2026-11-06T10:00:00+00:00'));
        $this->assertFalse($saga->isExpired('2026-11-05T10:00:00+00:00'));

        $saga->quoteApproved('p1', 'http://pay', '2026-11-07T10:00:00+00:00');
        $this->assertSame('p1', $saga->paymentId);

        $saga->paymentConfirmed();
        $this->assertNull($saga->deadlineAt);
        $this->assertFalse($saga->isExpired('2030-01-01T00:00:00+00:00'));

        $saga->executionQueued();
        $saga->completed();
        $this->assertSame(ServiceOrderSaga::COMPLETED, $saga->state);
        $this->assertTrue($saga->isFinished());
        $this->assertSame('q1', $saga->toArray()['quote_id']);
    }

    public function test_transitions_out_of_order_are_rejected(): void
    {
        $saga = ServiceOrderSaga::start('os-1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("esperado 'AWAITING_PAYMENT'");
        $saga->paymentConfirmed();
    }

    public function test_compensation_waits_for_all_confirmations(): void
    {
        $saga = ServiceOrderSaga::start('os-1');
        $saga->compensate('cancelada', [
            ServiceOrderSaga::COMPENSATION_CANCEL_EXECUTION,
            ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT,
            ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT,
        ]);

        $this->assertSame(ServiceOrderSaga::COMPENSATING, $saga->state);
        $this->assertCount(2, $saga->pendingCompensations);
        $this->assertTrue($saga->isWaitingFor(ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT));
        $this->assertFalse($saga->isWaitingFor(ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE));

        $this->assertFalse($saga->compensationConfirmed(ServiceOrderSaga::COMPENSATION_CANCEL_EXECUTION));
        $this->assertTrue($saga->compensationConfirmed(ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT));
        $this->assertSame(ServiceOrderSaga::CANCELLED, $saga->state);
    }

    public function test_compensate_without_actions_cancels_directly(): void
    {
        $saga = ServiceOrderSaga::start('os-1');
        $saga->compensate('nada a desfazer', []);

        $this->assertSame(ServiceOrderSaga::CANCELLED, $saga->state);
        $this->assertSame('nada a desfazer', $saga->failureReason);
    }

    public function test_compensation_failure(): void
    {
        $saga = ServiceOrderSaga::start('os-1');
        $saga->compensate('sem pecas', [ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT]);
        $saga->compensationFailed('gateway fora');

        $this->assertSame(ServiceOrderSaga::FAILED, $saga->state);
        $this->assertStringContainsString('gateway fora', $saga->failureReason);
        $this->assertTrue($saga->isFinished());
    }

    public function test_finished_saga_cannot_be_cancelled_or_compensated(): void
    {
        $saga = ServiceOrderSaga::start('os-1');
        $saga->cancel('recusado');

        try {
            $saga->cancel('de novo');
            $this->fail('ja encerrada');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        $saga->compensate('x', [ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE]);
    }

    public function test_invalid_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServiceOrderSaga('id', 'os', 'FLYING');
    }
}
