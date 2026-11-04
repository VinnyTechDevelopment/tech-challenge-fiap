<?php

namespace App\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\CreateVehicleDTO;
use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use App\Domain\Vehicle\ValueObjects\Plate;

class CreateVehicleUseCase
{
    public function __construct(
        private VehicleRepositoryInterface $repository
    ) {}

    public function execute(CreateVehicleDTO $dto): Vehicle
    {
        $plate = new Plate($dto->plate);

        $existing = $this->repository->findByPlate($plate->getValue());

        if ($existing) {
            throw new \DomainException('Veículo já cadastrado');
        }

        $vehicle = Vehicle::create(
            $dto->brand,
            $dto->model,
            $dto->year,
            $plate
        );

        $this->repository->save($vehicle);

        return $vehicle;
    }
}
