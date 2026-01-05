<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarProspectosRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Cambiar cuando se implemente autenticación
        return true;
        // return $this->user()->can('crear prospectos');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:51200'],
            'origen' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.required' => 'El archivo es obligatorio.',
            'archivo.file' => 'Debe proporcionar un archivo válido.',
            'archivo.mimes' => 'El archivo debe ser de tipo: xlsx, xls o csv.',
            'archivo.max' => 'El archivo no debe superar los 50MB.',
            'origen.required' => 'El origen es obligatorio.',
            'origen.string' => 'El origen debe ser texto.',
        ];
    }
}
