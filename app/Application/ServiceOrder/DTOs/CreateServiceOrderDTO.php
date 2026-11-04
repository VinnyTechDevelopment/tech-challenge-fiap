<?php

namespace App\Application\ServiceOrder\DTOs;

class CreateServiceOrderDTO
{
    /**
     * @param  array<int, array{service_id: string, quantity: float}>  $services
     * @param  array<int, array{item_id: string, quantity: float}>  $items
     */
    public function __construct(
        public string $vehicleId,
        public string $customerId,
        public array $services,
        public array $items = [],
    ) {}
}
