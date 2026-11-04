<?php

namespace App\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Serviços e peças são validados (e precificados) pelo execution-service,
     * dono do catálogo — não há mais "exists:services/items" aqui.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'required|uuid|exists:customers,id',
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'services' => 'required|array|min:1',
            'services.*.service_id' => 'required|string',
            'services.*.quantity' => 'required|numeric|min:0.01',
            'items' => 'nullable|array',
            'items.*.item_id' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
        ];
    }
}
