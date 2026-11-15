<?php

namespace App\Application\Customer\UseCases;

use App\Application\Customer\DTOs\ListCustomerDTO;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;

class ListCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $repository
    ) {}

    public function execute(ListCustomerDTO $dto): array
    {
        if ($dto->page !== null) {
            $perPage = $dto->perPage ?? 10;
            if ($perPage <= 0) {
                $perPage = 10;
            }

            return $this->repository->paginate($dto->page, $perPage);
        }

        return $this->repository->findAll();
    }
}
