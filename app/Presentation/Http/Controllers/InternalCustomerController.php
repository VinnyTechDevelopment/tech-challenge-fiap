<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Customer\UseCases\FindCustomerByDocumentUseCase;
use Illuminate\Http\JsonResponse;

class InternalCustomerController
{
    public function byDocument(string $document, FindCustomerByDocumentUseCase $useCase): JsonResponse
    {
        $customer = $useCase->execute($document);

        if (! $customer) {
            return response()->json(['message' => 'Cliente nao encontrado.'], 404);
        }

        return response()->json([
            'id' => $customer->id,
            'document' => $customer->document,
            'status' => $customer->status,
        ]);
    }
}
