<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearFlujoConProspectosRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Cambiar cuando se implemente autenticación
        return true;
        // return $this->user()->can('crear flujos');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Flujo
            'flujo' => ['nullable', 'array'],
            'flujo.nombre' => ['nullable', 'string', 'max:255'],
            'flujo.descripcion' => ['nullable', 'string'],
            // Acepta ID numérico o nombre string
            'flujo.tipo_prospecto' => ['nullable'],
            'flujo.activo' => ['nullable', 'boolean'],

            // Origen
            'origen_id' => ['nullable', 'string', 'max:255'],
            'origen_nombre' => ['nullable', 'string', 'max:255'],

            // Prospectos
            'prospectos' => ['nullable', 'array'],
            'prospectos.total_seleccionados' => ['nullable', 'integer', 'min:1'],
            'prospectos.ids_seleccionados' => ['nullable', 'array', 'min:1'],
            'prospectos.ids_seleccionados.*' => ['nullable', 'integer', 'exists:prospectos,id'],
            'prospectos.total_disponibles' => ['nullable', 'integer', 'min:0'],

            // Tipo de mensaje (opcional para FlowBuilder)
            'tipo_mensaje' => ['nullable', 'array'],
            'tipo_mensaje.tipo' => ['nullable', 'in:email,sms,ambos'],
            'tipo_mensaje.email_percentage' => ['required_if:tipo_mensaje.tipo,ambos', 'integer', 'min:0', 'max:100'],
            'tipo_mensaje.sms_percentage' => ['required_if:tipo_mensaje.tipo,ambos', 'integer', 'min:0', 'max:100'],

            // Distribución (opcional para FlowBuilder)
            'distribucion' => ['nullable', 'array'],
            'distribucion.email' => ['nullable', 'array'],
            'distribucion.email.cantidad' => ['nullable', 'integer', 'min:0'],
            'distribucion.email.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'distribucion.email.costo_total' => ['nullable', 'numeric', 'min:0'],

            'distribucion.sms' => ['nullable', 'array'],
            'distribucion.sms.cantidad' => ['nullable', 'integer', 'min:0'],
            'distribucion.sms.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'distribucion.sms.costo_total' => ['nullable', 'numeric', 'min:0'],

            'distribucion.resumen' => ['nullable', 'array'],
            'distribucion.resumen.total_prospectos' => ['nullable', 'integer', 'min:1'],
            'distribucion.resumen.costo_total' => ['nullable', 'numeric', 'min:0'],

            // FlowBuilder structure (opcional)
            'visual' => ['nullable', 'array'],
            'structure' => ['nullable', 'array'],

            // Metadata
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'flujo.nombre.required' => 'El nombre del flujo es obligatorio.',
            'flujo.tipo_prospecto.required' => 'El tipo de prospecto es obligatorio.',
            'origen_id.required' => 'El origen es obligatorio.',
            'prospectos.ids_seleccionados.required' => 'Debe seleccionar al menos un prospecto.',
            'prospectos.ids_seleccionados.min' => 'Debe seleccionar al menos un prospecto.',
            'prospectos.ids_seleccionados.*.exists' => 'Uno o más prospectos seleccionados no existen.',
            'tipo_mensaje.tipo.required' => 'El tipo de mensaje es obligatorio.',
            'tipo_mensaje.tipo.in' => 'El tipo de mensaje debe ser: email, sms o ambos.',
        ];
    }

    /**
     * Validate that percentages add up to 100 when tipo is 'ambos'.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('tipo_mensaje.tipo') === 'ambos') {
                $emailPercentage = $this->input('tipo_mensaje.email_percentage', 0);
                $smsPercentage = $this->input('tipo_mensaje.sms_percentage', 0);

                if ($emailPercentage + $smsPercentage !== 100) {
                    $validator->errors()->add(
                        'tipo_mensaje',
                        'Los porcentajes de email y SMS deben sumar 100%.'
                    );
                }
            }
        });
    }
}
