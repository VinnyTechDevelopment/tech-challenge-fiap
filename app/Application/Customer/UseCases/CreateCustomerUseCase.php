<?php

namespace App\Application\Customer\UseCases;

use App\Application\Customer\DTOs\CreateCustomerDTO;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Customer\ValueObjects\Document;

class CreateCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $repository
    ) {}

    public function execute(CreateCustomerDTO $dto): Customer
    {
        $document = new Document($dto->document);
        $existing = $this->repository->findByDocument($document->getValue());
        if ($existing) {
            throw new \DomainException('Cliente já cadastrado');
        }
        $customer = Customer::create(
            $dto->name,
            $dto->email,
            $dto->phone,
            $document->getValue()
        );
        $this->repository->save($customer);
        event(new CustomerCreated($customer));

        return $customer;
    }
}
