<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProspectoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Cambiar cuando se implemente autenticación
        return true;
        // return $this->user()->can('editar prospectos');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'rut' => ['sometimes', 'required', 'string', 'max:40'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('prospectos', 'email')->ignore($this->route('prospecto')),
            ],
            'telefono' => ['nullable', 'string', 'max:255'],
            'url_informe' => ['nullable', 'url', 'max:2048'],
            'tipo_prospecto_id' => ['nullable', 'integer', 'exists:tipo_prospecto,id'],
            'estado' => ['nullable', 'in:activo,inactivo,convertido'],
            'monto_deuda' => ['nullable', 'integer', 'min:0'],
            'fecha_ultimo_contacto' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.string' => 'El nombre debe ser texto.',
            'rut.string' => 'El RUT debe ser texto.',
            'rut.max' => 'El RUT no puede tener más de 40 caracteres.',
            'email.email' => 'El email debe ser una dirección válida.',
            'email.unique' => 'Este email ya está registrado.',
            'telefono.string' => 'El teléfono debe ser texto.',
            'url_informe.url' => 'La URL del informe debe ser una URL válida.',
            'url_informe.max' => 'La URL del informe no puede tener más de 2048 caracteres.',
            'tipo_prospecto_id.required' => 'El tipo de prospecto es obligatorio.',
            'tipo_prospecto_id.exists' => 'El tipo de prospecto seleccionado no existe.',
            'estado.in' => 'El estado debe ser: activo, inactivo o convertido.',
            'monto_deuda.numeric' => 'El monto de deuda debe ser un número.',
            'monto_deuda.min' => 'El monto de deuda no puede ser negativo.',
            'fecha_ultimo_contacto.date' => 'La fecha debe ser válida.',
            'metadata.array' => 'Los metadatos deben ser un objeto JSON válido.',
        ];
    }
}
