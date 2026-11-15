<?php

namespace Tests\Unit\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\ShowVehicleDTO;
use App\Application\Vehicle\UseCases\ShowVehicleUseCase;
use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use App\Domain\Vehicle\ValueObjects\Plate;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class ShowVehicleUseCaseTest extends TestCase
{
    private MockInterface $repository;

    private ShowVehicleUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(VehicleRepositoryInterface::class);
        $this->useCase = new ShowVehicleUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeVehicle(string $id = 'uuid-1111'): Vehicle
    {
        return new Vehicle(
            id: $id,
            brand: 'Toyota',
            model: 'Corolla',
            year: 2024,
            plate: new Plate('ABC1D23')
        );
    }

    public function test_returns_vehicle_when_found(): void
    {
        $vehicle = $this->makeVehicle('uuid-1111');

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with('uuid-1111')
            ->andReturn($vehicle);

        $result = $this->useCase->execute(new ShowVehicleDTO('uuid-1111'));

        $this->assertInstanceOf(Vehicle::class, $result);
        $this->assertSame('uuid-1111', $result->id);
        $this->assertSame('Toyota', $result->brand);
        $this->assertSame('ABC1D23', $result->plate->getValue());
    }

    public function test_throws_exception_when_vehicle_not_found(): void
    {
        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with('uuid-9999')
            ->andReturnNull();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Veículo não encontrado');

        $this->useCase->execute(new ShowVehicleDTO('uuid-9999'));
    }
}
