<?php

namespace App\Infrastructure\Http;

use App\Application\Exceptions\ServiceUnavailableException;
use App\Application\ServiceOrder\Ports\CatalogClientInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * POST {EXECUTION_SERVICE_URL}/api/catalog/lookup, repassando o JWT do
 * funcionário que abriu a OS (o execution-service valida a mesma assinatura).
 */
class HttpExecutionCatalogClient implements CatalogClientInterface
{
    public function __construct(
        private string $baseUrl,
        private int $timeoutSeconds,
        private ?string $bearerToken,
    ) {}

    public function lookup(array $services, array $items): array
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->withToken((string) $this->bearerToken)
                ->withHeaders(['X-Request-Id' => (string) request()->headers->get('X-Request-Id', '')])
                ->post('/api/catalog/lookup', ['services' => $services, 'items' => $items]);
        } catch (ConnectionException $e) {
            throw new ServiceUnavailableException('Catalogo (execution-service) indisponivel.', 0, $e);
        }

        if ($response->status() === 422) {
            throw new \DomainException((string) $response->json('message', 'Itens invalidos no catalogo.'));
        }

        if (! $response->successful()) {
            throw new ServiceUnavailableException("Catalogo (execution-service) respondeu HTTP {$response->status()}.");
        }

        return [
            'services' => (array) $response->json('services', []),
            'items' => (array) $response->json('items', []),
        ];
    }
}
