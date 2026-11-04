<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CPF = '52998224725';

    private const VALID_CPF2 = '11144477735';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['document' => self::VALID_CPF]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria Oliveira',
            'email' => 'maria@example.com',
            'phone' => '11999990000',
            'document' => self::VALID_CPF2,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // POST /api/customer
    // -------------------------------------------------------------------------

    public function test_store_creates_customer_and_returns_200(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload());

        $response->assertStatus(200)
            ->assertJsonStructure(['customer' => ['id', 'name', 'email', 'phone', 'document']])
            ->assertJsonFragment([
                'name' => 'Maria Oliveira',
                'email' => 'maria@example.com',
                'document' => self::VALID_CPF2,
            ]);

        $this->assertDatabaseHas('customers', ['email' => 'maria@example.com']);
    }

    public function test_store_returns_401_without_authentication(): void
    {
        $this->postJson('/api/customer', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_store_returns_422_when_document_already_registered(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload());

        $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload(['email' => 'outro@example.com']))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cliente já cadastrado']);
    }

    public function test_store_returns_422_for_invalid_document(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload(['document' => '00000000000']))
            ->assertStatus(422);
    }

    public function test_store_returns_422_for_missing_required_fields(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', [])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // GET /api/customer
    // -------------------------------------------------------------------------

    public function test_list_returns_200_with_customers_array(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload());

        $this->actingAs($this->user, 'api')
            ->getJson('/api/customer')
            ->assertStatus(200)
            ->assertJsonIsArray();
    }

    public function test_list_returns_401_without_authentication(): void
    {
        $this->getJson('/api/customer')
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // GET /api/customer/{id}
    // -------------------------------------------------------------------------

    public function test_show_returns_customer_by_id(): void
    {
        $createResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload());

        $id = $createResponse->json('customer.id');

        $this->actingAs($this->user, 'api')
            ->getJson("/api/customer/{$id}")
            ->assertStatus(200)
            ->assertJsonStructure(['customer' => ['id', 'name', 'email', 'phone', 'document']])
            ->assertJsonFragment(['id' => $id]);
    }

    public function test_show_returns_422_for_unknown_id(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/customer/non-existent-uuid')
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // PUT /api/customer/{id}
    // -------------------------------------------------------------------------

    public function test_update_changes_customer_fields(): void
    {
        $createResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/customer', $this->validPayload());

        $id = $createResponse->json('customer.id');

        $this->actingAs($this->user, 'api')
            ->putJson("/api/customer/{$id}", [
                'name' => 'Maria Atualizada',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Maria Atualizada']);
    }

    public function test_update_returns_401_without_authentication(): void
    {
        $this->putJson('/api/customer/some-uuid', ['name' => 'Nome'])
            ->assertStatus(401);
    }
}
