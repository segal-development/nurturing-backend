<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearPlantillaEmailRequest extends FormRequest
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
            'asunto' => ['required', 'string', 'max:200'],
            'componentes' => ['required', 'array', 'min:1'],
            'componentes.*.tipo' => ['required', 'in:logo,texto,boton,separador,imagen,footer'],
            'componentes.*.id' => ['required', 'string'],
            'componentes.*.orden' => ['required', 'integer'],
            // ✅ Permitir campos adicionales según el tipo de componente
            'componentes.*.contenido' => ['nullable', 'string'],
            'componentes.*.url' => ['nullable', 'string'],
            'componentes.*.altura' => ['nullable', 'integer'],
            'componentes.*.alineacion' => ['nullable', 'string'],
            'componentes.*.tamano' => ['nullable', 'integer'],
            'componentes.*.color' => ['nullable', 'string'],
            'componentes.*.color_fondo' => ['nullable', 'string'],
            'componentes.*.color_texto' => ['nullable', 'string'],
            'componentes.*.texto' => ['nullable', 'string'],
            // Campos específicos para componente imagen
            'componentes.*.alt' => ['nullable', 'string', 'max:200'],
            'componentes.*.ancho' => ['nullable', 'integer', 'min:50', 'max:600'],
            'componentes.*.link_url' => ['nullable', 'string', 'url'],
            'componentes.*.link_target' => ['nullable', 'string', 'in:_blank,_self'],
            'componentes.*.border_radius' => ['nullable', 'integer', 'min:0', 'max:50'],
            'componentes.*.padding' => ['nullable', 'integer', 'min:0', 'max:50'],
            'activo' => ['sometimes', 'boolean'],
            'tipo' => ['required', 'in:email'], // Validar que tipo sea 'email'
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la plantilla es obligatorio',
            'nombre.max' => 'El nombre no puede exceder 100 caracteres',
            'descripcion.max' => 'La descripción no puede exceder 500 caracteres',
            'asunto.required' => 'El asunto del email es obligatorio',
            'asunto.max' => 'El asunto no puede exceder 200 caracteres',
            'componentes.required' => 'Debe incluir al menos un componente',
            'componentes.min' => 'Debe incluir al menos un componente',
            'componentes.*.tipo.required' => 'Cada componente debe tener un tipo',
            'componentes.*.tipo.in' => 'Tipo de componente no válido',
        ];
    }

    /**
     * Preparar datos para validación
     */
    protected function prepareForValidation(): void
    {
        // Forzar tipo Email sin importar lo que venga del frontend
        $this->replace(array_merge($this->all(), [
            'tipo' => 'email',
        ]));
    }
}
