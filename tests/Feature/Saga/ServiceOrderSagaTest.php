<?php

namespace Tests\Feature\Saga;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageTypes;
use App\Domain\Saga\Entities\ServiceOrderSaga;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SagaTestHarness;
use Tests\TestCase;

/**
 * Saga orquestrada da OS (os-service como orquestrador), cobrindo o caminho
 * feliz e todas as compensações.
 */
class ServiceOrderSagaTest extends TestCase
{
    use RefreshDatabase;

    private SagaTestHarness $saga;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saga = new SagaTestHarness($this->app);
        $this->staff = $this->saga->givenStaff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{id: string, saga_id: string, command: Message}
     */
    private function openOrder(array $items = [['item_id' => 'item-filtro', 'quantity' => 2]]): array
    {
        $ids = $this->saga->givenCustomerWithVehicle($this->staff);

        $response = $this->actingAs($this->staff, 'api')->postJson('/api/service-order', $ids + [
            'services' => [['service_id' => 'svc-revisao', 'quantity' => 1]],
            'items' => $items,
        ])->assertStatus(202);

        return [
            'id' => $response->json('service_order.id'),
            'saga_id' => $response->json('saga.id'),
            'command' => $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_CREATE_QUOTE),
        ];
    }

    private function orderStatus(string $id): string
    {
        return (string) DB::table('service_orders')->where('id', $id)->value('status');
    }

    private function sagaState(string $sagaId): string
    {
        return (string) DB::table('service_order_sagas')->where('id', $sagaId)->value('state');
    }

    /**
     * Leva a OS até "pagamento confirmado" e devolve o comando de reserva.
     */
    private function payOrder(array $order): Message
    {
        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1', 'approval_url' => 'http://b/approve', 'reject_url' => 'http://b/reject', 'total' => 391]);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_QUOTE_APPROVED, ['quote_id' => 'q-1', 'payment_id' => 'p-1', 'payment_url' => 'http://mp/checkout']);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_PAYMENT_CONFIRMED, ['payment_id' => 'p-1', 'amount' => 391]);

        return $this->saga->lastCommand(MessageTypes::QUEUE_EXECUTION_COMMANDS, MessageTypes::EXECUTION_RESERVE_AND_ENQUEUE);
    }

    public function test_happy_path_open_quote_approve_pay_execute_finish_deliver(): void
    {
        $order = $this->openOrder();

        $this->assertSame('recebida', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::QUOTE_REQUESTED, $this->sagaState($order['saga_id']));
        $this->assertSame(391.0, (float) $order['command']->get('services.0.unit_price') + 2 * (float) $order['command']->get('items.0.unit_price'));
        $this->assertSame($order['saga_id'], $order['command']->sagaId);
        $this->assertSame('os-service', $order['command']->source);

        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1', 'approval_url' => 'http://b/approve', 'reject_url' => 'http://b/reject', 'total' => 391]);
        $this->assertSame('aguardando_aprovacao', $this->orderStatus($order['id']));
        $this->assertDatabaseHas('notifications', ['subject' => 'Orçamento da sua ordem de serviço disponível']);

        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_QUOTE_APPROVED, ['quote_id' => 'q-1', 'payment_id' => 'p-1', 'payment_url' => 'http://mp/checkout']);
        $this->assertSame('aguardando_pagamento', $this->orderStatus($order['id']));
        $this->assertDatabaseHas('notifications', ['subject' => 'Orçamento aprovado — conclua o pagamento']);

        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_PAYMENT_CONFIRMED, ['payment_id' => 'p-1', 'amount' => 391]);
        $reserve = $this->saga->lastCommand(MessageTypes::QUEUE_EXECUTION_COMMANDS, MessageTypes::EXECUTION_RESERVE_AND_ENQUEUE);
        $this->assertSame([['item_id' => 'item-filtro', 'quantity' => 2]], $reserve->get('items'));
        $this->assertSame('svc-revisao', $reserve->get('services.0.service_id'));
        $this->assertNotNull(DB::table('service_orders')->where('id', $order['id'])->value('paid_at'));

        $this->saga->reply($reserve, MessageTypes::EXECUTION_QUEUED);
        $this->assertSame('aguardando_execucao', $this->orderStatus($order['id']));

        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_STATUS_CHANGED, ['status' => 'in_diagnosis']);
        $this->assertSame('em_diagnostico', $this->orderStatus($order['id']));
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_STATUS_CHANGED, ['status' => 'in_repair']);
        $this->assertSame('em_execucao', $this->orderStatus($order['id']));
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_FINISHED);

        $this->assertSame('finalizada', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::COMPLETED, $this->sagaState($order['saga_id']));

        $this->actingAs($this->staff, 'api')
            ->patchJson("/api/service-order/{$order['id']}/status", ['status' => 'entregue'])
            ->assertOk();

        $history = $this->actingAs($this->staff, 'api')->getJson("/api/service-order/{$order['id']}/history")
            ->assertOk()
            ->assertJsonPath('saga.state', ServiceOrderSaga::COMPLETED)
            ->json();

        $this->assertSame(
            ['recebida', 'aguardando_aprovacao', 'aguardando_pagamento', 'aguardando_execucao', 'em_diagnostico', 'em_execucao', 'finalizada', 'entregue'],
            array_column($history['history'], 'to_status')
        );
        $this->assertContains('create_quote', array_column($history['saga_steps'], 'step'));
    }

    public function test_quote_rejected_cancels_order_without_further_commands(): void
    {
        $order = $this->openOrder();
        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1']);

        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_QUOTE_REJECTED, ['quote_id' => 'q-1']);

        $this->assertSame('cancelada', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::CANCELLED, $this->sagaState($order['saga_id']));
        $this->assertSame([], $this->saga->takeCommands(MessageTypes::QUEUE_BILLING_COMMANDS));
        $this->assertSame('Orcamento recusado pelo cliente.', DB::table('service_orders')->where('id', $order['id'])->value('cancellation_reason'));
    }

    public function test_quote_creation_failure_cancels_order(): void
    {
        $order = $this->openOrder();

        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATION_FAILED, ['reason' => 'preco invalido']);

        $this->assertSame('cancelada', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::CANCELLED, $this->sagaState($order['saga_id']));
    }

    public function test_payment_failure_compensates_by_cancelling_quote(): void
    {
        $order = $this->openOrder();
        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1']);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_QUOTE_APPROVED, ['payment_id' => 'p-1']);

        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_PAYMENT_FAILED, ['reason' => 'cartao recusado']);

        $this->assertSame(ServiceOrderSaga::COMPENSATING, $this->sagaState($order['saga_id']));
        $this->assertSame('aguardando_pagamento', $this->orderStatus($order['id']), 'só cancela quando a compensação for confirmada');

        $cancel = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_CANCEL_QUOTE);
        $this->assertSame('q-1', $cancel->get('quote_id'));

        $this->saga->reply($cancel, MessageTypes::BILLING_QUOTE_CANCELLED);

        $this->assertSame('cancelada', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::CANCELLED, $this->sagaState($order['saga_id']));
    }

    public function test_parts_reservation_failure_refunds_payment(): void
    {
        $order = $this->openOrder();
        $reserve = $this->payOrder($order);

        $this->saga->reply($reserve, MessageTypes::EXECUTION_RESERVATION_FAILED, ['reason' => 'Insufficient stock']);

        $refund = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_REFUND_PAYMENT);
        $this->assertSame('p-1', $refund->get('payment_id'));
        $this->assertSame($reserve->correlationId, $refund->correlationId);
        $this->assertSame(ServiceOrderSaga::COMPENSATING, $this->sagaState($order['saga_id']));

        $this->saga->reply($refund, MessageTypes::BILLING_PAYMENT_REFUNDED, ['amount' => 391]);

        $this->assertSame('cancelada', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::CANCELLED, $this->sagaState($order['saga_id']));

        $steps = DB::table('saga_steps')->where('saga_id', $order['saga_id'])->pluck('outcome', 'step');
        $this->assertSame('compensated', $steps['compensation:refund_payment']);
    }

    public function test_refund_failure_marks_saga_failed_for_manual_action(): void
    {
        $order = $this->openOrder();
        $reserve = $this->payOrder($order);
        $this->saga->reply($reserve, MessageTypes::EXECUTION_RESERVATION_FAILED, ['reason' => 'sem pecas']);
        $refund = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_REFUND_PAYMENT);

        $this->saga->reply($refund, MessageTypes::BILLING_PAYMENT_REFUND_FAILED, ['reason' => 'gateway fora']);

        $this->assertSame(ServiceOrderSaga::FAILED, $this->sagaState($order['saga_id']));
        $this->assertStringContainsString('gateway fora', (string) DB::table('service_order_sagas')->where('id', $order['saga_id'])->value('failure_reason'));
    }

    public function test_staff_cancellation_during_execution_releases_parts_and_refunds(): void
    {
        $order = $this->openOrder();
        $reserve = $this->payOrder($order);
        $this->saga->reply($reserve, MessageTypes::EXECUTION_QUEUED);

        $this->actingAs($this->staff, 'api')
            ->postJson("/api/service-order/{$order['id']}/cancel", ['reason' => 'cliente desistiu'])
            ->assertStatus(202)
            ->assertJsonPath('saga.state', ServiceOrderSaga::COMPENSATING);

        $cancelExecution = $this->saga->lastCommand(MessageTypes::QUEUE_EXECUTION_COMMANDS, MessageTypes::EXECUTION_CANCEL);
        $refund = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_REFUND_PAYMENT);

        $this->saga->reply($cancelExecution, MessageTypes::EXECUTION_CANCELLED);
        $this->assertSame(ServiceOrderSaga::COMPENSATING, $this->sagaState($order['saga_id']), 'ainda falta o estorno');

        $this->saga->reply($refund, MessageTypes::BILLING_PAYMENT_REFUNDED, ['amount' => 391]);
        $this->assertSame('cancelada', $this->orderStatus($order['id']));
        $this->assertSame(ServiceOrderSaga::CANCELLED, $this->sagaState($order['saga_id']));
    }

    public function test_staff_cancellation_before_payment_cancels_quote(): void
    {
        $order = $this->openOrder();
        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1']);

        $this->actingAs($this->staff, 'api')
            ->postJson("/api/service-order/{$order['id']}/cancel", ['reason' => 'erro de cadastro'])
            ->assertStatus(202);

        $cancel = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_CANCEL_QUOTE);
        $this->saga->deliver(Message::create(MessageTypes::BILLING_QUOTE_CANCELLED, $order['saga_id'], ['service_order_id' => $order['id']]));

        $this->assertSame('cancelada', $this->orderStatus($order['id']));
        $this->assertSame('erro de cadastro', explode(': ', (string) $cancel->get('reason'))[1]);
    }

    public function test_finished_order_cannot_be_cancelled(): void
    {
        $order = $this->openOrder();
        $reserve = $this->payOrder($order);
        $this->saga->reply($reserve, MessageTypes::EXECUTION_QUEUED);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_FINISHED);

        $this->actingAs($this->staff, 'api')
            ->postJson("/api/service-order/{$order['id']}/cancel", ['reason' => 'x'])
            ->assertUnprocessable();
    }

    public function test_approval_timeout_compensates_and_cancels(): void
    {
        $order = $this->openOrder();
        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1']);

        $this->artisan('saga:timeouts')->expectsOutputToContain('0 saga(s)')->assertSuccessful();

        Carbon::setTestNow(now()->addMinutes((int) config('saga.approval_timeout_minutes') + 1));
        $this->artisan('saga:timeouts')->expectsOutputToContain('1 saga(s)')->assertSuccessful();

        $cancel = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_CANCEL_QUOTE);
        $this->assertSame('Prazo para aprovacao do orcamento expirou.', $cancel->get('reason'));

        $this->saga->deliver(Message::create(MessageTypes::BILLING_QUOTE_CANCELLED, $order['saga_id'], ['service_order_id' => $order['id']]));
        $this->assertSame('cancelada', $this->orderStatus($order['id']));
    }

    public function test_payment_timeout_and_late_payment_refund_is_audited(): void
    {
        $order = $this->openOrder();
        $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1']);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_QUOTE_APPROVED, ['payment_id' => 'p-1']);

        Carbon::setTestNow(now()->addMinutes((int) config('saga.payment_timeout_minutes') + 1));
        $this->artisan('saga:timeouts')->assertSuccessful();
        $cancel = $this->saga->lastCommand(MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_CANCEL_QUOTE);
        $this->assertSame('Prazo para pagamento expirou.', $cancel->get('reason'));
        $this->saga->reply($cancel, MessageTypes::BILLING_QUOTE_CANCELLED);

        // Cliente pagou depois do prazo: billing estornou sozinho e avisa.
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::BILLING_PAYMENT_REFUNDED, ['late_payment' => true, 'amount' => 391]);

        $this->assertSame(ServiceOrderSaga::CANCELLED, $this->sagaState($order['saga_id']));
        $this->assertDatabaseHas('saga_steps', ['saga_id' => $order['saga_id'], 'step' => 'late_payment', 'outcome' => 'compensated']);
    }

    public function test_out_of_order_execution_events_are_applied_in_sequence(): void
    {
        $order = $this->openOrder();
        $this->payOrder($order);

        // ExecutionFinished chegou antes de ExecutionQueued/StatusChanged (SQS Standard).
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_FINISHED);
        $this->assertSame('finalizada', $this->orderStatus($order['id']));

        // Mensagens atrasadas não regridem a OS.
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_STATUS_CHANGED, ['status' => 'in_repair']);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_QUEUED);
        $this->assertSame('finalizada', $this->orderStatus($order['id']));
        $this->assertDatabaseHas('saga_steps', ['saga_id' => $order['saga_id'], 'outcome' => 'ignored']);
    }

    public function test_duplicate_reply_is_processed_once(): void
    {
        $order = $this->openOrder();
        $reply = $this->saga->reply($order['command'], MessageTypes::BILLING_QUOTE_CREATED, ['quote_id' => 'q-1']);
        $this->saga->deliver($reply);

        $this->assertSame(1, DB::table('saga_steps')->where('saga_id', $order['saga_id'])->where('step', 'create_quote')->where('outcome', 'completed')->count());
        $this->assertSame(1, DB::table('processed_messages')->where('message_id', $reply->id)->count());
    }

    public function test_unknown_saga_or_status_is_ignored_safely(): void
    {
        $order = $this->openOrder();
        $this->saga->deliver(Message::create(MessageTypes::BILLING_QUOTE_CREATED, 'saga-inexistente', []));

        $reserve = $this->payOrder($order);
        $this->saga->reply($reserve, MessageTypes::EXECUTION_QUEUED);
        $this->saga->event($order['saga_id'], $order['id'], MessageTypes::EXECUTION_STATUS_CHANGED, ['status' => 'voando']);

        $this->assertSame('aguardando_execucao', $this->orderStatus($order['id']));
        $this->assertDatabaseHas('saga_steps', ['saga_id' => $order['saga_id'], 'step' => 'unexpected_message']);
    }

    public function test_confirmation_for_compensation_not_requested_is_ignored(): void
    {
        $order = $this->openOrder();
        $this->saga->deliver(Message::create(MessageTypes::EXECUTION_CANCELLED, $order['saga_id'], ['service_order_id' => $order['id']]));

        $this->assertSame(ServiceOrderSaga::QUOTE_REQUESTED, $this->sagaState($order['saga_id']));
        $this->assertDatabaseHas('saga_steps', ['saga_id' => $order['saga_id'], 'step' => 'compensation:cancel_execution', 'outcome' => 'ignored']);
    }
}
