<?php

namespace App\Application\Customer\UseCases;

use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;

class DeleteCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $repository
    ) {}

    public function execute(string $id): void
    {
        $customer = $this->repository->findById($id);

        if (! $customer) {
            throw new \DomainException('Customer not found.');
        }

        $this->repository->delete($id);
    }
}
