<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Customer\UseCases\CreateCustomerUseCase;
use App\Application\Customer\UseCases\DeleteCustomerUseCase;
use App\Application\Customer\UseCases\ListCustomerUseCase;
use App\Application\Customer\UseCases\ShowCustomerUseCase;
use App\Application\Customer\UseCases\UpdateCustomerUseCase;
use App\Application\Customer\DTOs\CreateCustomerDTO;
use App\Application\Customer\DTOs\ListCustomerDTO;
use App\Application\Customer\DTOs\ShowCustomerDTO;
use App\Application\Customer\DTOs\UpdateCustomerDTO;
use App\Presentation\Http\Requests\CreateCustomerRequest;
use App\Presentation\Http\Requests\ListCustomerRequest;
use App\Presentation\Http\Requests\UpdateCustomerRequest;
use Illuminate\Http\Request;

class CustomerController
{
    /**
     * Perfil do próprio cliente autenticado via CPF (guard "auth.customer",
     * JWT emitido pela Lambda lambda-auth-cpf) — não usa o guard "api".
     */
    public function me(Request $request, ShowCustomerUseCase $useCase)
    {
        $dto = new ShowCustomerDTO(id: $request->attributes->get('customer_id'));
        $customer = $useCase->execute($dto);
        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'document' => $customer->document,
            ],
        ]);
    }

    public function store(CreateCustomerRequest $request, CreateCustomerUseCase $useCase)
    {
        $dto = new CreateCustomerDTO(
            $request->name,
            $request->email,
            $request->phone,
            $request->document
        );
        $customer = $useCase->execute($dto);
        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'document' => $customer->document,
            ],
        ]);
    }
    public function list(ListCustomerRequest $request, ListCustomerUseCase $useCase)
    {
        $dto = new ListCustomerDTO(
            page: $request->input('page', 1),
            perPage: $request->input('perPage', 10)
        );
        $customers = $useCase->execute($dto);
        return response()->json($customers);
    }

    public function show(string $id, ShowCustomerUseCase $useCase)
    {
        $dto = new ShowCustomerDTO(id: $id);
        $customer = $useCase->execute($dto);
        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'document' => $customer->document,
            ],
        ]);
    }
    public function update(UpdateCustomerRequest $request, UpdateCustomerUseCase $useCase)
    {
        $dto = new UpdateCustomerDTO(
            id: $request->route('id'),
            name: $request->input('name'),
            email: $request->input('email'),
            phone: $request->input('phone'),
            document: $request->input('document')
        );
        $customer = $useCase->execute($dto);
        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'document' => $customer->document,
            ],
        ]);
    }

    public function destroy(string $id, DeleteCustomerUseCase $useCase)
    {
        $useCase->execute($id);
        return response()->json(null, 204);
    }
}