<?php

namespace App\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'string|max:255',
            'email' => ['email', Rule::unique('customers')->ignore($this->route('id'))],
            'phone' => 'string|max:255',
            'document' => 'string|max:14',
        ];
    }
}
