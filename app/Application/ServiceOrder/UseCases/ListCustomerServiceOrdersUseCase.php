<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;

/**
 * Cliente autenticado por CPF (Lambda) consulta as próprias OS.
 */
class ListCustomerServiceOrdersUseCase
{
    public function __construct(private ServiceOrderRepositoryInterface $orders) {}

    public function execute(string $customerId): array
    {
        return $this->orders->findByCustomerId($customerId);
    }
}
