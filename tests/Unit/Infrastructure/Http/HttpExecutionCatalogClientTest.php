<?php

namespace Tests\Unit\Infrastructure\Http;

use App\Application\Exceptions\ServiceUnavailableException;
use App\Infrastructure\Http\HttpExecutionCatalogClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpExecutionCatalogClientTest extends TestCase
{
    private function client(): HttpExecutionCatalogClient
    {
        return new HttpExecutionCatalogClient('http://execution.svc/', 3, 'staff-jwt');
    }

    public function test_posts_lookup_forwarding_staff_token(): void
    {
        Http::fake(['execution.svc/api/catalog/lookup' => Http::response([
            'services' => [['service_id' => 's', 'name' => 'Revisao', 'quantity' => 1, 'unit_price' => 100]],
            'items' => [],
        ])]);

        $result = $this->client()->lookup([['service_id' => 's', 'quantity' => 1]], []);

        $this->assertSame('Revisao', $result['services'][0]['name']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer staff-jwt')
            && $r['services'][0]['service_id'] === 's');
    }

    public function test_maps_422_to_domain_exception(): void
    {
        Http::fake(['*' => Http::response(['message' => "Peca 'x' nao encontrada."], 422)]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Peca 'x' nao encontrada.");
        $this->client()->lookup([], [['item_id' => 'x', 'quantity' => 1]]);
    }

    public function test_maps_server_errors_to_unavailable(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(ServiceUnavailableException::class);
        $this->client()->lookup([], []);
    }

    public function test_maps_connection_errors_to_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(ServiceUnavailableException::class);
        $this->client()->lookup([], []);
    }
}
