<?php

namespace App\Application\Customer\UseCases;

use App\Application\Customer\DTOs\ShowCustomerDTO;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;

class ShowCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $repository
    ) {}

    public function execute(ShowCustomerDTO $dto): Customer
    {
        $customer = $this->repository->findById($dto->id);
        if (! $customer) {
            throw new \DomainException('Cliente não encontrado');
        }

        return $customer;
    }
}
