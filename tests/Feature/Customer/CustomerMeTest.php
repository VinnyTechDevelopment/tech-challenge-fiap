<?php

namespace Tests\Feature\Customer;

use App\Infrastructure\Persistence\Eloquent\Models\CustomerModel;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerMeTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CPF = '52998224725';

    private function createCustomer(array $overrides = []): CustomerModel
    {
        $user = User::factory()->create();

        return CustomerModel::create(array_merge([
            'id' => Str::uuid()->toString(),
            'name' => 'Cliente Teste',
            'email' => 'cliente@example.com',
            'phone' => '11999990000',
            'document' => self::VALID_CPF,
            'created_user_id' => $user->id,
            'updated_user_id' => $user->id,
        ], $overrides));
    }

    private function tokenFor(string $customerId, array $claims = []): string
    {
        return JWT::encode(array_merge([
            'sub' => $customerId,
            'document' => self::VALID_CPF,
            'type' => 'customer',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claims), config('services.customer_jwt.secret'), 'HS256');
    }

    public function test_returns_own_profile_with_valid_customer_token(): void
    {
        $customer = $this->createCustomer();
        $token = $this->tokenFor($customer->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/customer/me')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $customer->id, 'email' => $customer->email]);
    }

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/api/customer/me')->assertStatus(401);
    }

    public function test_returns_401_with_malformed_authorization_header(): void
    {
        $this->withHeader('Authorization', 'Token abc')
            ->getJson('/api/customer/me')
            ->assertStatus(401);
    }

    public function test_returns_401_with_token_signed_by_wrong_secret(): void
    {
        $customer = $this->createCustomer();
        $token = JWT::encode([
            'sub' => $customer->id,
            'type' => 'customer',
            'iat' => time(),
            'exp' => time() + 3600,
        ], 'segredo-completamente-diferente-do-configurado-nos-testes', 'HS256');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/customer/me')
            ->assertStatus(401);
    }

    public function test_returns_401_with_expired_token(): void
    {
        $customer = $this->createCustomer();
        $token = $this->tokenFor($customer->id, ['iat' => time() - 7200, 'exp' => time() - 3600]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/customer/me')
            ->assertStatus(401);
    }

    public function test_returns_401_when_token_type_is_not_customer(): void
    {
        $customer = $this->createCustomer();
        $token = $this->tokenFor($customer->id, ['type' => 'staff']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/customer/me')
            ->assertStatus(401);
    }

    public function test_returns_422_when_customer_no_longer_exists(): void
    {
        $token = $this->tokenFor('00000000-0000-0000-0000-000000000000');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/customer/me')
            ->assertStatus(422);
    }
}
