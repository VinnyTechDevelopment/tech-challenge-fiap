<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Vehicle\DTOs\CreateVehicleDTO;
use App\Application\Vehicle\DTOs\ListVehicleDTO;
use App\Application\Vehicle\DTOs\ShowVehicleDTO;
use App\Application\Vehicle\DTOs\UpdateVehicleDto;
use App\Application\Vehicle\UseCases\CreateVehicleUseCase;
use App\Application\Vehicle\UseCases\DeleteVehicleUseCase;
use App\Application\Vehicle\UseCases\ListVehicleUseCase;
use App\Application\Vehicle\UseCases\ShowVehicleUseCase;
use App\Application\Vehicle\UseCases\UpdateVehicleUseCase;
use App\Domain\Vehicle\ValueObjects\Plate;
use App\Presentation\Http\Requests\CreateVehicleRequest;
use App\Presentation\Http\Requests\ListVehicleRequest;
use App\Presentation\Http\Requests\UpdateVehicleRequest;

class VehicleController
{
    public function store(CreateVehicleRequest $request, CreateVehicleUseCase $useCase)
    {
        $dto = new CreateVehicleDTO(
            $request->brand,
            $request->model,
            $request->year,
            $request->plate
        );

        $vehicle = $useCase->execute($dto);

        return response()->json([
            'id' => $vehicle->id,
            'message' => 'Veículo cadastrado com sucesso',
        ]);
    }

    public function list(ListVehicleRequest $request, ListVehicleUseCase $useCase)
    {

        $dto = new ListVehicleDTO(
            page: $request->input('page', 1),
            perPage: $request->input('perPage', 10)
        );

        $vehicles = $useCase->execute($dto);

        return response()->json($vehicles);
    }

    public function show(string $id, ShowVehicleUseCase $useCase)
    {
        $dto = new ShowVehicleDTO(
            id: $id
        );

        $vehicle = $useCase->execute($dto);

        return response()->json([
            'id' => $vehicle->id,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'plate' => $vehicle->plate->getValue(),
        ]);
    }

    public function update(UpdateVehicleRequest $request, UpdateVehicleUseCase $useCase)
    {
        $dto = new UpdateVehicleDto(
            id: $request->route('id'),
            brand: $request->input('brand'),
            model: $request->input('model'),
            year: $request->input('year'),
            plate: $request->filled('plate') ? new Plate($request->input('plate')) : null
        );

        $vehicle = $useCase->execute($dto);

        return response()->json([
            'vehicle' => [
                'id' => $vehicle->id,
                'model' => $vehicle->model,
                'brand' => $vehicle->brand,
                'year' => $vehicle->year,
                'plate' => $vehicle->plate->getValue(),
            ],
            'message' => 'Veículo atualizado com sucesso',
        ], 200);
    }

    public function destroy(string $id, DeleteVehicleUseCase $useCase)
    {
        $useCase->execute($id);

        return response()->json(null, 204);
    }
}
