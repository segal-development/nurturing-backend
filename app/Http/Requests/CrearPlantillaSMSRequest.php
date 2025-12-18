<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearPlantillaSMSRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'contenido' => ['required', 'string', 'max:160'],
            'activo' => ['sometimes', 'boolean'],
            'tipo' => ['required', 'in:sms'], // Validar que tipo sea 'sms'
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la plantilla es obligatorio',
            'nombre.max' => 'El nombre no puede exceder 100 caracteres',
            'descripcion.max' => 'La descripción no puede exceder 500 caracteres',
            'contenido.required' => 'El contenido del SMS es obligatorio',
            'contenido.max' => 'El contenido no puede exceder 160 caracteres',
        ];
    }

    /**
     * Preparar datos para validación
     */
    protected function prepareForValidation(): void
    {
        // Forzar tipo SMS sin importar lo que venga del frontend
        $this->replace(array_merge($this->all(), [
            'tipo' => 'sms',
        ]));
    }
}
