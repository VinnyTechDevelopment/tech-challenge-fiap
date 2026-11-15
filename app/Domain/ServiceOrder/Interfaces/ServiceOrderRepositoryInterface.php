<?php

namespace App\Domain\ServiceOrder\Interfaces;

use App\Domain\ServiceOrder\Entities\ServiceOrder;

interface ServiceOrderRepositoryInterface
{
    public function save(ServiceOrder $serviceOrder): void;

    public function findById(string $id): ?ServiceOrder;

    public function findAll(): array;

    public function paginate(int $page, int $perPage): array;

    /**
     * @return array<int, array> OS do cliente (mais recentes primeiro)
     */
    public function findByCustomerId(string $customerId): array;

    public function update(ServiceOrder $serviceOrder): void;

    public function delete(string $id): void;

    public function recordStatusChange(string $serviceOrderId, ?string $from, string $to, string $source): void;

    /**
     * @return array<int, array{from_status: ?string, to_status: string, source: string, created_at: string}>
     */
    public function statusHistory(string $serviceOrderId): array;
}
