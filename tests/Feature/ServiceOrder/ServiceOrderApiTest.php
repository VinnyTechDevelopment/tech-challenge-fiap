<?php

namespace Tests\Feature\ServiceOrder;

use App\Application\Messaging\MessageTypes;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SagaTestHarness;
use Tests\TestCase;

class ServiceOrderApiTest extends TestCase
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

    private function open(array $overrides = []): TestResponse
    {
        $ids = $this->saga->givenCustomerWithVehicle($this->staff);

        return $this->actingAs($this->staff, 'api')->postJson('/api/service-order', array_merge($ids + [
            'services' => [['service_id' => 'svc-alinhamento', 'quantity' => 1]],
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/service-order', [])->assertUnauthorized();
    }

    public function test_create_returns_202_with_priced_snapshot_and_saga(): void
    {
        $this->open(['items' => [['item_id' => 'item-pastilha', 'quantity' => 2]]])
            ->assertStatus(202)
            ->assertJsonPath('service_order.status', 'recebida')
            ->assertJsonPath('service_order.services.0.name', 'Alinhamento')
            ->assertJsonPath('service_order.total_budget', 480)
            ->assertJsonPath('saga.state', 'QUOTE_REQUESTED');

        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', ['type' => MessageTypes::BILLING_CREATE_QUOTE, 'queue' => MessageTypes::QUEUE_BILLING_COMMANDS]);
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->staff, 'api')->postJson('/api/service-order', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'vehicle_id', 'services']);
    }

    public function test_unknown_catalog_entry_returns_422_and_nothing_is_persisted(): void
    {
        $this->open(['services' => [['service_id' => 'svc-nao-existe', 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Servico 'svc-nao-existe' nao encontrado.");

        $this->assertDatabaseCount('service_orders', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_catalog_unavailable_returns_503(): void
    {
        $this->saga->catalog->unavailable = true;

        $this->open()->assertStatus(503);
        $this->assertDatabaseCount('service_orders', 0);
    }

    public function test_show_list_and_history(): void
    {
        $id = $this->open()->json('service_order.id');

        $this->actingAs($this->staff, 'api')->getJson("/api/service-order/{$id}")
            ->assertOk()
            ->assertJsonPath('service_order.id', $id)
            ->assertJsonPath('saga.state', 'QUOTE_REQUESTED');

        $this->actingAs($this->staff, 'api')->getJson('/api/service-order?page=1&perPage=5')
            ->assertOk()
            ->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.saga.state', 'QUOTE_REQUESTED');

        $this->actingAs($this->staff, 'api')->getJson("/api/service-order/{$id}/history")
            ->assertOk()
            ->assertJsonPath('history.0.to_status', 'recebida')
            ->assertJsonPath('saga_steps.0.step', 'create_quote');

        $this->actingAs($this->staff, 'api')->getJson('/api/service-order/00000000-0000-0000-0000-000000000000')->assertUnprocessable();
        $this->actingAs($this->staff, 'api')->getJson('/api/service-order/00000000-0000-0000-0000-000000000000/history')->assertUnprocessable();
    }

    public function test_manual_status_only_allows_delivery(): void
    {
        $id = $this->open()->json('service_order.id');

        $this->actingAs($this->staff, 'api')->patchJson("/api/service-order/{$id}/status", ['status' => 'em_execucao'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'controlado pela saga'));

        $this->actingAs($this->staff, 'api')->patchJson("/api/service-order/{$id}/status", ['status' => 'entregue'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Transicao de status invalida: recebida -> entregue.');

        $this->actingAs($this->staff, 'api')->patchJson('/api/service-order/00000000-0000-0000-0000-000000000000/status', ['status' => 'entregue'])
            ->assertUnprocessable();
    }

    public function test_delete_only_final_orders(): void
    {
        $id = $this->open()->json('service_order.id');

        $this->actingAs($this->staff, 'api')->deleteJson("/api/service-order/{$id}")->assertUnprocessable();

        DB::table('service_orders')->where('id', $id)->update(['status' => 'cancelada']);
        $this->actingAs($this->staff, 'api')->deleteJson("/api/service-order/{$id}")->assertNoContent();
        $this->assertSoftDeleted('service_orders', ['id' => $id]);

        $this->actingAs($this->staff, 'api')->deleteJson('/api/service-order/00000000-0000-0000-0000-000000000000')->assertUnprocessable();
    }

    public function test_cancel_validation_and_unknown_order(): void
    {
        $id = $this->open()->json('service_order.id');

        $this->actingAs($this->staff, 'api')->postJson("/api/service-order/{$id}/cancel", [])->assertUnprocessable();
        $this->actingAs($this->staff, 'api')->postJson('/api/service-order/00000000-0000-0000-0000-000000000000/cancel', ['reason' => 'x'])
            ->assertUnprocessable();

        DB::table('service_order_sagas')->delete();
        $this->actingAs($this->staff, 'api')->postJson("/api/service-order/{$id}/cancel", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Saga da ordem de servico nao encontrada.');
    }

    public function test_customer_lists_own_service_orders_with_cpf_token(): void
    {
        $response = $this->open();
        $customerId = $response->json('service_order.customer_id');

        $token = JWT::encode(['sub' => $customerId, 'type' => 'customer', 'exp' => time() + 60], (string) config('services.customer_jwt.secret'), 'HS256');

        $this->getJson('/api/customer/me/service-orders', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.0.id', $response->json('service_order.id'))
            ->assertJsonPath('data.0.status', 'recebida');
    }
}
