<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CPF = '52998224725';

    private const VALID_CPF_2 = '73127709006';

    // -------------------------------------------------------------------------
    // POST /api/auth/register
    // -------------------------------------------------------------------------

    public function test_register_creates_user_and_returns_201(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'João Silva',
            'email' => 'joao@example.com',
            'document' => self::VALID_CPF,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'user'])
            ->assertJsonFragment(['email' => 'joao@example.com']);

        $this->assertDatabaseHas('users', ['email' => 'joao@example.com']);
    }

    public function test_register_returns_400_when_email_already_taken(): void
    {
        User::factory()->create([
            'email' => 'joao@example.com',
            'document' => self::VALID_CPF,
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Outro João',
            'email' => 'joao@example.com',
            'document' => self::VALID_CPF_2,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(400);
    }

    public function test_register_returns_400_when_document_already_taken(): void
    {
        User::factory()->create([
            'email' => 'primeiro@example.com',
            'document' => self::VALID_CPF,
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Segundo',
            'email' => 'segundo@example.com',
            'document' => self::VALID_CPF,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(400);
    }

    public function test_register_returns_400_when_missing_required_fields(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(400);
    }

    public function test_register_returns_422_when_document_is_invalid_cpf(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'João',
            'email' => 'joao@example.com',
            'document' => '00000000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    public function test_register_returns_400_when_passwords_do_not_match(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'João',
            'email' => 'joao@example.com',
            'document' => self::VALID_CPF,
            'password' => 'password123',
            'password_confirmation' => 'wrongpassword',
        ])->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/login
    // -------------------------------------------------------------------------

    public function test_login_returns_access_token_on_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'joao@example.com',
            'password' => bcrypt('password123'),
            'document' => self::VALID_CPF,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'joao@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type'])
            ->assertJsonFragment(['token_type' => 'bearer']);
    }

    public function test_login_returns_401_on_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'joao@example.com',
            'password' => bcrypt('password123'),
            'document' => self::VALID_CPF,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'joao@example.com',
            'password' => 'wrongpassword',
        ])->assertStatus(401);
    }

    public function test_login_returns_401_for_unknown_email(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'noone@example.com',
            'password' => 'password123',
        ])->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // GET /api/auth/me
    // -------------------------------------------------------------------------

    public function test_me_returns_user_when_authenticated(): void
    {
        $user = User::factory()->create(['document' => self::VALID_CPF]);

        $this->actingAs($user, 'api')
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_me_returns_401_without_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/logout
    // -------------------------------------------------------------------------

    public function test_logout_returns_success_message(): void
    {
        $user = User::factory()->create(['document' => self::VALID_CPF]);

        $this->actingAs($user, 'api')
            ->postJson('/api/auth/logout')
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Usuário deslogado com sucesso']);
    }

    // -------------------------------------------------------------------------
    // POST /api/auth/refresh
    // -------------------------------------------------------------------------

    public function test_refresh_returns_new_token(): void
    {
        User::factory()->create([
            'email' => 'refresh@example.com',
            'password' => bcrypt('password123'),
            'document' => self::VALID_CPF,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'refresh@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/refresh')
            ->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }
}
