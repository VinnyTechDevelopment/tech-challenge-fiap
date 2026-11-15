<?php

namespace Tests\Unit\Domain\ServiceOrder\Entities;

use App\Domain\ServiceOrder\Entities\ServiceOrder;
use PHPUnit\Framework\TestCase;

class ServiceOrderTest extends TestCase
{
    private function makeServiceOrder(): ServiceOrder
    {
        return ServiceOrder::create(
            'cust-1',
            'veh-1',
            [['service_id' => 'svc-1', 'name' => 'Alinhamento', 'quantity' => 2.0, 'unit_price' => 100.0]],
            [['item_id' => 'item-1', 'name' => 'Filtro', 'quantity' => 3.0, 'unit_price' => 20.0]]
        );
    }

    public function test_create_generates_uuid_and_calculates_totals(): void
    {
        $order = $this->makeServiceOrder();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $order->id);
        $this->assertSame(ServiceOrder::STATUS_RECEBIDA, $order->status);
        $this->assertSame(200.0, $order->servicesTotal);
        $this->assertSame(60.0, $order->itemsTotal);
        $this->assertSame(260.0, $order->totalBudget);
        $this->assertFalse($order->isFinal());
    }

    public function test_create_requires_at_least_one_service(): void
    {
        $this->expectException(\DomainException::class);
        ServiceOrder::create('c', 'v', [], []);
    }

    public function test_saga_driven_lifecycle(): void
    {
        $order = $this->makeServiceOrder();

        $order->quoteSent('t1');
        $this->assertSame(ServiceOrder::STATUS_AGUARDANDO_APROVACAO, $order->status);
        $this->assertSame('t1', $order->quoteSentAt);

        $order->quoteApproved('t2');
        $this->assertSame(ServiceOrder::STATUS_AGUARDANDO_PAGAMENTO, $order->status);
        $this->assertSame('t2', $order->quoteApprovedAt);

        $order->paymentConfirmed('t3');
        $this->assertSame('t3', $order->paidAt);
        $this->assertSame(ServiceOrder::STATUS_AGUARDANDO_PAGAMENTO, $order->status);

        foreach ([
            ServiceOrder::STATUS_AGUARDANDO_EXECUCAO,
            ServiceOrder::STATUS_EM_DIAGNOSTICO,
            ServiceOrder::STATUS_EM_EXECUCAO,
            ServiceOrder::STATUS_FINALIZADA,
            ServiceOrder::STATUS_ENTREGUE,
        ] as $status) {
            $order->changeStatus($status);
            $this->assertSame($status, $order->status);
        }

        $this->assertTrue($order->isFinal());
    }

    public function test_cannot_skip_or_go_back(): void
    {
        $order = $this->makeServiceOrder();

        try {
            $order->changeStatus(ServiceOrder::STATUS_FINALIZADA);
            $this->fail('nao pode pular etapas');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('recebida -> finalizada', $e->getMessage());
        }

        $order->quoteSent('t');
        $this->expectException(\DomainException::class);
        $order->changeStatus(ServiceOrder::STATUS_RECEBIDA);
    }

    public function test_payment_confirmation_requires_awaiting_payment(): void
    {
        $this->expectException(\DomainException::class);
        $this->makeServiceOrder()->paymentConfirmed('t');
    }

    public function test_cancel_records_reason_and_is_final(): void
    {
        $order = $this->makeServiceOrder();
        $order->cancel('cliente desistiu');

        $this->assertSame(ServiceOrder::STATUS_CANCELADA, $order->status);
        $this->assertSame('cliente desistiu', $order->cancellationReason);
        $this->assertTrue($order->isFinal());
        $this->assertFalse($order->canTransitionTo(ServiceOrder::STATUS_RECEBIDA));
    }

    public function test_finished_order_cannot_be_cancelled(): void
    {
        $order = new ServiceOrder('id', 'c', 'v', [], [], ServiceOrder::STATUS_FINALIZADA, 0, 0, 0);

        $this->expectException(\DomainException::class);
        $order->cancel('tarde demais');
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServiceOrder('id', 'c', 'v', [], [], 'voando', 0, 0, 0);
    }

    public function test_unknown_target_status_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeServiceOrder()->changeStatus('voando');
    }

    public function test_allowed_statuses_lists_all(): void
    {
        $this->assertCount(9, ServiceOrder::allowedStatuses());
        $this->assertContains(ServiceOrder::STATUS_CANCELADA, ServiceOrder::allowedStatuses());
    }
}
