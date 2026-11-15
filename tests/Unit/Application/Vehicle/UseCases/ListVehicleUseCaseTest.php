<?php

namespace Tests\Unit\Application\Vehicle\UseCases;

use App\Application\Vehicle\DTOs\ListVehicleDTO;
use App\Application\Vehicle\UseCases\ListVehicleUseCase;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class ListVehicleUseCaseTest extends TestCase
{
    private VehicleRepositoryInterface&MockInterface $repository;

    private ListVehicleUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(VehicleRepositoryInterface::class);
        $this->useCase = new ListVehicleUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_calls_find_all_when_no_page_is_provided(): void
    {
        $dto = new ListVehicleDTO(page: null, perPage: null);
        $vehicles = ['v1', 'v2'];

        $this->repository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn($vehicles);

        $this->repository->shouldNotReceive('paginate');

        $result = $this->useCase->execute($dto);

        $this->assertSame($vehicles, $result);
    }

    public function test_calls_paginate_when_page_is_provided(): void
    {
        $dto = new ListVehicleDTO(page: 2, perPage: 15);
        $paginated = ['data' => [], 'total' => 0, 'page' => 2, 'perPage' => 15];

        $this->repository
            ->shouldReceive('paginate')
            ->once()
            ->with(2, 15)
            ->andReturn($paginated);

        $this->repository->shouldNotReceive('findAll');

        $result = $this->useCase->execute($dto);

        $this->assertSame($paginated, $result);
    }

    public function test_defaults_per_page_to_10_when_invalid(): void
    {
        $dto = new ListVehicleDTO(page: 1, perPage: 0);

        $this->repository
            ->shouldReceive('paginate')
            ->once()
            ->with(1, 10)
            ->andReturn([]);

        $result = $this->useCase->execute($dto);

        $this->assertSame([], $result);
    }
}
