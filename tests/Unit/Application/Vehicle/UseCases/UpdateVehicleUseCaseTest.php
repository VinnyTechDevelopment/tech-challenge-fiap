<?php

namespace Tests\Unit\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\UpdateVehicleDto;
use App\Application\Vehicle\UseCases\UpdateVehicleUseCase;
use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use App\Domain\Vehicle\ValueObjects\Plate;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class UpdateVehicleUseCaseTest extends TestCase
{
    private MockInterface $repository;

    private UpdateVehicleUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(VehicleRepositoryInterface::class);
        $this->useCase = new UpdateVehicleUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeVehicle(string $id = 'uuid-1111', string $plate = 'ABC1D23'): Vehicle
    {
        return new Vehicle(
            id: $id,
            brand: 'Toyota',
            model: 'Corolla',
            year: 2023,
            plate: new Plate($plate)
        );
    }

    public function test_updates_vehicle_data_successfully(): void
    {
        $vehicle = $this->makeVehicle('uuid-1111', 'ABC1D23');
        $dto = new UpdateVehicleDto(
            id: 'uuid-1111',
            brand: 'Honda',
            model: 'Civic',
            year: 2025,
            plate: new Plate('DEF2G34')
        );

        $this->repository->shouldReceive('findById')->once()->with('uuid-1111')->andReturn($vehicle);
        $this->repository->shouldReceive('findByPlate')->once()->with('DEF2G34')->andReturnNull();
        $this->repository->shouldReceive('update')->once()->with($vehicle);

        $result = $this->useCase->execute($dto);

        $this->assertSame('Honda', $result->brand);
        $this->assertSame('Civic', $result->model);
        $this->assertSame(2025, $result->year);
        $this->assertSame('DEF2G34', $result->plate->getValue());
    }

    public function test_throws_exception_when_vehicle_not_found(): void
    {
        $dto = new UpdateVehicleDto(
            id: 'uuid-9999',
            brand: null,
            model: null,
            year: null,
            plate: null
        );

        $this->repository->shouldReceive('findById')->once()->with('uuid-9999')->andReturnNull();
        $this->repository->shouldNotReceive('findByPlate');
        $this->repository->shouldNotReceive('update');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Veículo não encontrado');

        $this->useCase->execute($dto);
    }

    public function test_throws_exception_when_plate_belongs_to_another_vehicle(): void
    {
        $vehicle = $this->makeVehicle('uuid-1111', 'ABC1D23');
        $otherVehicle = $this->makeVehicle('uuid-2222', 'DEF2G34');
        $dto = new UpdateVehicleDto(
            id: 'uuid-1111',
            brand: null,
            model: null,
            year: null,
            plate: new Plate('DEF2G34')
        );

        $this->repository->shouldReceive('findById')->once()->with('uuid-1111')->andReturn($vehicle);
        $this->repository->shouldReceive('findByPlate')->once()->with('DEF2G34')->andReturn($otherVehicle);
        $this->repository->shouldNotReceive('update');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Placa já cadastrada para outro veículo');

        $this->useCase->execute($dto);
    }

    public function test_allows_same_plate_when_it_belongs_to_same_vehicle(): void
    {
        $vehicle = $this->makeVehicle('uuid-1111', 'ABC1D23');
        $dto = new UpdateVehicleDto(
            id: 'uuid-1111',
            brand: 'Toyota',
            model: null,
            year: null,
            plate: new Plate('ABC1D23')
        );

        $this->repository->shouldReceive('findById')->once()->with('uuid-1111')->andReturn($vehicle);
        $this->repository->shouldReceive('findByPlate')->once()->with('ABC1D23')->andReturn($vehicle);
        $this->repository->shouldReceive('update')->once()->with($vehicle);

        $result = $this->useCase->execute($dto);

        $this->assertSame('Toyota', $result->brand);
        $this->assertSame('ABC1D23', $result->plate->getValue());
    }

    public function test_skips_duplicate_plate_check_when_plate_is_not_provided(): void
    {
        $vehicle = $this->makeVehicle('uuid-1111', 'ABC1D23');
        $dto = new UpdateVehicleDto(
            id: 'uuid-1111',
            brand: 'Nissan',
            model: null,
            year: null,
            plate: null
        );

        $this->repository->shouldReceive('findById')->once()->with('uuid-1111')->andReturn($vehicle);
        $this->repository->shouldNotReceive('findByPlate');
        $this->repository->shouldReceive('update')->once()->with($vehicle);

        $result = $this->useCase->execute($dto);

        $this->assertSame('Nissan', $result->brand);
        $this->assertSame('ABC1D23', $result->plate->getValue());
    }
}
