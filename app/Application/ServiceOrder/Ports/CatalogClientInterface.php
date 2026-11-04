<?php

namespace App\Application\ServiceOrder\Ports;

use App\Application\Exceptions\ServiceUnavailableException;

/**
 * Porta para o catálogo de serviços e peças, que pertence ao
 * execution-service. Comunicação síncrona (REST): ao abrir a OS precisamos
 * da resposta na hora para validar e precificar os itens.
 */
interface CatalogClientInterface
{
    /**
     * @param  array<int, array{service_id: string, quantity: float}>  $services
     * @param  array<int, array{item_id: string, quantity: float}>  $items
     * @return array{services: array<int, array>, items: array<int, array>} linhas com nome e preço
     *
     * @throws \DomainException serviço/peça inexistente
     * @throws ServiceUnavailableException execution-service indisponível
     */
    public function lookup(array $services, array $items): array;
}
