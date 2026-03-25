<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CohortProspectosRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'node_id' => ['nullable', 'string'],
            'envio_estado' => ['nullable', 'string', 'in:pendiente,enviado,fallido,abierto,clickeado'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default per_page if not provided
        if (! $this->has('per_page')) {
            $this->merge(['per_page' => 50]);
        }
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => 'La página debe ser un número entero.',
            'page.min' => 'La página debe ser al menos 1.',
            'per_page.integer' => 'El límite por página debe ser un número entero.',
            'per_page.min' => 'El límite por página debe ser al menos 1.',
            'per_page.max' => 'El límite por página no puede ser mayor a 100.',
            'envio_estado.in' => 'El estado de envío debe ser: pendiente, enviado, fallido, abierto o clickeado.',
            'search.max' => 'El término de búsqueda no puede exceder 100 caracteres.',
        ];
    }
}
