<?php

namespace Tests\Unit\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\CreateVehicleDTO;
use App\Application\Vehicle\UseCases\CreateVehicleUseCase;
use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class CreateVehicleUseCaseTest extends TestCase
{
    private MockInterface $repository;

    private CreateVehicleUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(VehicleRepositoryInterface::class);
        $this->useCase = new CreateVehicleUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeDTO(string $plate = 'ABC1D23'): CreateVehicleDTO
    {
        return new CreateVehicleDTO(
            brand: 'Toyota',
            model: 'Corolla',
            year: 2024,
            plate: $plate
        );
    }

    public function test_creates_vehicle_and_saves_to_repository(): void
    {
        $this->repository
            ->shouldReceive('findByPlate')
            ->once()
            ->with('ABC1D23')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::type(Vehicle::class));

        $vehicle = $this->useCase->execute($this->makeDTO());

        $this->assertInstanceOf(Vehicle::class, $vehicle);
        $this->assertSame('Toyota', $vehicle->brand);
        $this->assertSame('Corolla', $vehicle->model);
        $this->assertSame(2024, $vehicle->year);
        $this->assertSame('ABC1D23', $vehicle->plate->getValue());
    }

    public function test_throws_exception_when_plate_already_exists(): void
    {
        $existingVehicle = Mockery::mock(Vehicle::class);

        $this->repository
            ->shouldReceive('findByPlate')
            ->once()
            ->with('ABC1D23')
            ->andReturn($existingVehicle);

        $this->repository->shouldNotReceive('save');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Veículo já cadastrado');

        $this->useCase->execute($this->makeDTO());
    }

    public function test_throws_when_plate_value_object_is_invalid(): void
    {
        $this->repository->shouldNotReceive('findByPlate');
        $this->repository->shouldNotReceive('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Placa inválida');

        $this->useCase->execute($this->makeDTO('INVALIDA'));
    }
}
