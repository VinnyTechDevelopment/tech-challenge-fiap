<?php

namespace App\Domain\Customer\Interfaces;

use App\Domain\Customer\Entities\Customer;

interface CustomerRepositoryInterface
{
    public function save(Customer $customer): void;

    public function findById(string $id): ?Customer;

    public function findByDocument(string $document): ?Customer;

    public function findByEmail(string $email): ?Customer;

    public function findAll(): array;

    public function paginate(int $page, int $perPage): array;

    public function update(Customer $customer): void;

    public function delete(string $id): void;
}
