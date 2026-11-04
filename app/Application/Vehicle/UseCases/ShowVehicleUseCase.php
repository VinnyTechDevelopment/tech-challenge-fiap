<?php

namespace App\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\ShowVehicleDTO;
use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;

class ShowVehicleUseCase
{
    public function __construct(
        private VehicleRepositoryInterface $repository
    ) {}

    public function execute(ShowVehicleDTO $dto): Vehicle
    {
        $vehicle = $this->repository->findById($dto->id);

        if (! $vehicle) {
            throw new \DomainException('Veículo não encontrado');
        }

        return $vehicle;
    }
}
