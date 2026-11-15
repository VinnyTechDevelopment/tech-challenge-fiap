<?php

namespace App\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\UpdateVehicleDto;
use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;

class UpdateVehicleUseCase
{
    public function __construct(
        private VehicleRepositoryInterface $repository
    ) {}

    public function execute(UpdateVehicleDto $dto): Vehicle
    {
        $vehicle = $this->repository->findById($dto->id);

        if (! $vehicle) {
            throw new \DomainException('Veículo não encontrado');
        }

        if ($dto->plate !== null && $dto->plate->getValue() !== null) {
            $exists = $this->repository->findByPlate($dto->plate->getValue());

            if ($exists && $exists->id !== $vehicle->id) {
                throw new \DomainException('Placa já cadastrada para outro veículo');
            }
        }

        $vehicle->updateData(
            $dto->brand,
            $dto->model,
            $dto->year,
            $dto->plate
        );

        $this->repository->update($vehicle);

        return $vehicle;
    }
}
