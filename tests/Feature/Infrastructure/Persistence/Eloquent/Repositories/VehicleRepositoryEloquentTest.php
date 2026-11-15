<?php

namespace Tests\Feature\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\ValueObjects\Plate;
use App\Infrastructure\Persistence\Eloquent\Repositories\VehicleRepositoryEloquent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleRepositoryEloquentTest extends TestCase
{
    use RefreshDatabase;

    private VehicleRepositoryEloquent $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new VehicleRepositoryEloquent;
    }

    private function authenticateUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function makeVehicle(
        string $brand = 'Toyota',
        string $model = 'Corolla',
        int $year = 2020,
        string $plate = 'ABC1D23'
    ): Vehicle {
        return new Vehicle(
            Str::uuid()->toString(),
            $brand,
            $model,
            $year,
            new Plate($plate)
        );
    }

    public function test_save_persists_vehicle_with_audit_user_ids(): void
    {
        $user = $this->authenticateUser();
        $vehicle = $this->makeVehicle();

        $this->repository->save($vehicle);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'plate' => 'ABC1D23',
            'created_user_id' => $user->id,
            'updated_user_id' => $user->id,
        ]);
    }

    public function test_find_by_plate_returns_vehicle_when_found(): void
    {
        $this->authenticateUser();
        $vehicle = $this->makeVehicle(plate: 'DEF2G34');
        $this->repository->save($vehicle);

        $found = $this->repository->findByPlate('DEF2G34');

        $this->assertNotNull($found);
        $this->assertSame($vehicle->id, $found->id);
        $this->assertSame('DEF2G34', $found->plate->getValue());
    }

    public function test_find_by_plate_returns_null_when_not_found(): void
    {
        $found = $this->repository->findByPlate('ZZZ9Z99');

        $this->assertNull($found);
    }

    public function test_find_by_id_returns_vehicle_when_found(): void
    {
        $this->authenticateUser();
        $vehicle = $this->makeVehicle(plate: 'GHI3J45');
        $this->repository->save($vehicle);

        $found = $this->repository->findById($vehicle->id);

        $this->assertNotNull($found);
        $this->assertSame($vehicle->id, $found->id);
        $this->assertSame('GHI3J45', $found->plate->getValue());
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $found = $this->repository->findById('non-existent-id');

        $this->assertNull($found);
    }

    public function test_find_all_returns_all_vehicles_as_array(): void
    {
        $this->authenticateUser();
        $this->repository->save($this->makeVehicle(brand: 'A', model: 'M1', plate: 'JKL4M56'));
        $this->repository->save($this->makeVehicle(brand: 'B', model: 'M2', plate: 'NOP5Q67'));

        $all = $this->repository->findAll();

        $this->assertCount(2, $all);
        $this->assertIsArray($all[0]);
        $this->assertArrayHasKey('id', $all[0]);
        $this->assertArrayHasKey('plate', $all[0]);
    }

    public function test_paginate_returns_expected_page_size(): void
    {
        $this->authenticateUser();

        $plates = ['QRS6T78', 'UVW7X89', 'YZA8B90', 'CDE9F01', 'FGH0I12'];
        foreach ($plates as $i => $plate) {
            $this->repository->save($this->makeVehicle(
                brand: "Brand {$i}",
                model: "Model {$i}",
                year: 2018 + $i,
                plate: $plate
            ));
        }

        $pageTwo = $this->repository->paginate(2, 2);

        $this->assertCount(2, $pageTwo);
        $this->assertIsArray($pageTwo[0]);
        $this->assertArrayHasKey('brand', $pageTwo[0]);
    }

    public function test_update_persists_new_vehicle_data(): void
    {
        $firstUser = $this->authenticateUser();
        $vehicle = $this->makeVehicle();
        $this->repository->save($vehicle);

        $secondUser = User::factory()->create();
        $this->actingAs($secondUser);

        $vehicle->updateData('Honda', 'Civic', 2024, new Plate('LMN1O23'));
        $this->repository->update($vehicle);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'brand' => 'Honda',
            'model' => 'Civic',
            'year' => 2024,
            'plate' => 'LMN1O23',
            'created_user_id' => $firstUser->id,
            'updated_user_id' => $secondUser->id,
        ]);
    }
}
