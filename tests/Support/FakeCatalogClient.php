<?php

namespace Tests\Support;

use App\Application\Exceptions\ServiceUnavailableException;
use App\Application\ServiceOrder\Ports\CatalogClientInterface;

/**
 * Substitui a chamada REST ao execution-service nos testes.
 */
class FakeCatalogClient implements CatalogClientInterface
{
    /** @var array<string, array{name: string, price: float}> */
    public array $services = [
        'svc-revisao' => ['name' => 'Revisao completa', 'price' => 300.0],
        'svc-alinhamento' => ['name' => 'Alinhamento', 'price' => 120.0],
    ];

    /** @var array<string, array{name: string, price: float}> */
    public array $items = [
        'item-filtro' => ['name' => 'Filtro de oleo', 'price' => 45.5],
        'item-pastilha' => ['name' => 'Pastilha de freio', 'price' => 180.0],
    ];

    public bool $unavailable = false;

    public function lookup(array $services, array $items): array
    {
        if ($this->unavailable) {
            throw new ServiceUnavailableException('Catalogo (execution-service) indisponivel.');
        }

        return [
            'services' => array_map(function (array $line) {
                $entry = $this->services[$line['service_id']] ?? throw new \DomainException("Servico '{$line['service_id']}' nao encontrado.");

                return ['service_id' => $line['service_id'], 'name' => $entry['name'], 'quantity' => (float) $line['quantity'], 'unit_price' => $entry['price']];
            }, $services),
            'items' => array_map(function (array $line) {
                $entry = $this->items[$line['item_id']] ?? throw new \DomainException("Peca '{$line['item_id']}' nao encontrada.");

                return ['item_id' => $line['item_id'], 'name' => $entry['name'], 'quantity' => (float) $line['quantity'], 'unit_price' => $entry['price']];
            }, $items),
        ];
    }
}
