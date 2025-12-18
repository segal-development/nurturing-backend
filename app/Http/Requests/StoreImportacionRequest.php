<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportacionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Cambiar cuando se implemente autenticación
        return true;
        // return $this->user()->can('ver prospectos');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'origen' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'in:procesando,completado,fallido'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
        ];
    }

    public function messages(): array
    {
        return [
            'origen.string' => 'El origen debe ser texto.',
            'estado.in' => 'El estado debe ser: procesando, completado o fallido.',
            'fecha_desde.date' => 'La fecha desde debe ser válida.',
            'fecha_hasta.date' => 'La fecha hasta debe ser válida.',
            'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser posterior o igual a la fecha desde.',
        ];
    }
}
