<?php

namespace Tests\Feature\Customer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SagaTestHarness;
use Tests\TestCase;

/**
 * Endpoint consumido pela Lambda lambda-auth-cpf (que deixou de acessar o
 * banco do os-service diretamente).
 */
class InternalCustomerEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function headers(?string $token = null): array
    {
        return ['X-Internal-Token' => $token ?? (string) config('services.internal_api.token')];
    }

    public function test_returns_customer_by_cpf_with_or_without_punctuation(): void
    {
        $ids = (new SagaTestHarness($this->app))->givenCustomerWithVehicle(document: '52998224725');

        $this->getJson('/api/internal/customers/by-document/52998224725', $this->headers())
            ->assertOk()
            ->assertExactJson(['id' => $ids['customer_id'], 'document' => '52998224725', 'status' => 'active']);

        $this->getJson('/api/internal/customers/by-document/529.982.247-25', $this->headers())
            ->assertOk()
            ->assertJsonPath('id', $ids['customer_id']);
    }

    public function test_reports_inactive_status(): void
    {
        (new SagaTestHarness($this->app))->givenCustomerWithVehicle(document: '52998224725');
        DB::table('customers')->update(['status' => 'inactive']);

        $this->getJson('/api/internal/customers/by-document/52998224725', $this->headers())
            ->assertOk()
            ->assertJsonPath('status', 'inactive');
    }

    public function test_not_found_and_invalid_document(): void
    {
        $this->getJson('/api/internal/customers/by-document/52998224725', $this->headers())->assertNotFound();
        $this->getJson('/api/internal/customers/by-document/123', $this->headers())->assertUnprocessable();
    }

    public function test_requires_internal_token(): void
    {
        $this->getJson('/api/internal/customers/by-document/52998224725')->assertUnauthorized();
        $this->getJson('/api/internal/customers/by-document/52998224725', $this->headers('errado'))->assertUnauthorized();

        config(['services.internal_api.token' => '']);
        $this->getJson('/api/internal/customers/by-document/52998224725', $this->headers(''))->assertUnauthorized();
    }
}
