<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Vehicle\Entities\Vehicle;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use App\Domain\Vehicle\ValueObjects\Plate;
use App\Infrastructure\Persistence\Eloquent\Models\VehicleModel;
use Illuminate\Support\Facades\Auth;

class VehicleRepositoryEloquent implements VehicleRepositoryInterface
{
    public function save(Vehicle $vehicle): void
    {
        VehicleModel::create([
            'id' => $vehicle->id,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'plate' => $vehicle->plate->getValue(),
            'created_user_id' => Auth::id(),
            'updated_user_id' => Auth::id(),
        ]);
    }

    public function findByPlate(string $plate): ?Vehicle
    {
        $model = VehicleModel::where('plate', $plate)->first();

        if (! $model) {
            return null;
        }

        return new Vehicle(
            $model->id,
            $model->brand,
            $model->model,
            $model->year,
            new Plate($model->plate)
        );
    }

    public function paginate(int $page, int $perPage): array
    {
        return VehicleModel::query()
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->toArray();
    }

    public function findAll(): array
    {
        $models = VehicleModel::all()->toArray();

        return $models;
    }

    public function findById(string $id): ?Vehicle
    {
        $model = VehicleModel::find($id);

        if (! $model) {
            return null;
        }

        return new Vehicle(
            $model->id,
            $model->brand,
            $model->model,
            $model->year,
            new Plate($model->plate)
        );
    }

    public function update(Vehicle $vehicle): void
    {
        VehicleModel::where('id', $vehicle->id)->update([
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'plate' => $vehicle->plate->getValue(),
            'updated_user_id' => Auth::id(),
        ]);
    }

    public function delete(string $id): void
    {
        VehicleModel::where('id', $id)->delete();
    }
}
