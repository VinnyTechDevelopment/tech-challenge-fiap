<?php

namespace App\Application\Vehicle\UseCases;

use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;

class DeleteVehicleUseCase
{
    public function __construct(
        private VehicleRepositoryInterface $repository
    ) {}

    public function execute(string $id): void
    {
        $vehicle = $this->repository->findById($id);

        if (! $vehicle) {
            throw new \DomainException('Vehicle not found.');
        }

        $this->repository->delete($id);
    }
}
