<?php

namespace App\Application\Customer\UseCases;

use App\Application\Customer\DTOs\UpdateCustomerDTO;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;

class UpdateCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $repository
    ) {}

    public function execute(UpdateCustomerDTO $dto): Customer
    {
        $customer = $this->repository->findById($dto->id);
        if (! $customer) {
            throw new \DomainException('Cliente não encontrado');
        }
        $customer->updateData(
            $dto->name,
            $dto->email,
            $dto->phone,
            $dto->document
        );
        $this->repository->update($customer);

        return $customer;
    }
}
