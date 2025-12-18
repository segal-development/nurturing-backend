<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConfiguracionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
        // TODO: Cambiar cuando se implemente autenticación
        // return $this->user()->can('editar configuracion');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Costos (obligatorios)
            'email_costo' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'sms_costo' => ['required', 'numeric', 'min:0', 'max:999999.99'],

            // Límites (opcionales)
            'max_prospectos_por_flujo' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_emails_por_dia' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_sms_por_dia' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'reintentos_envio' => ['nullable', 'integer', 'min:0', 'max:10'],

            // Notificaciones (opcionales)
            'notificar_flujo_completado' => ['nullable', 'boolean'],
            'notificar_errores_envio' => ['nullable', 'boolean'],
            'email_notificaciones' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'email_costo.required' => 'El costo de email es obligatorio.',
            'email_costo.numeric' => 'El costo de email debe ser un número.',
            'email_costo.min' => 'El costo de email no puede ser negativo.',

            'sms_costo.required' => 'El costo de SMS es obligatorio.',
            'sms_costo.numeric' => 'El costo de SMS debe ser un número.',
            'sms_costo.min' => 'El costo de SMS no puede ser negativo.',

            'max_prospectos_por_flujo.integer' => 'El máximo de prospectos debe ser un número entero.',
            'max_prospectos_por_flujo.min' => 'El máximo de prospectos debe ser al menos 1.',

            'max_emails_por_dia.integer' => 'El máximo de emails por día debe ser un número entero.',
            'max_emails_por_dia.min' => 'El máximo de emails por día debe ser al menos 1.',

            'max_sms_por_dia.integer' => 'El máximo de SMS por día debe ser un número entero.',
            'max_sms_por_dia.min' => 'El máximo de SMS por día debe ser al menos 1.',

            'reintentos_envio.integer' => 'Los reintentos de envío deben ser un número entero.',
            'reintentos_envio.min' => 'Los reintentos de envío no pueden ser negativos.',
            'reintentos_envio.max' => 'Los reintentos de envío no pueden ser más de 10.',

            'notificar_flujo_completado.boolean' => 'La notificación de flujo completado debe ser verdadero o falso.',
            'notificar_errores_envio.boolean' => 'La notificación de errores debe ser verdadero o falso.',

            'email_notificaciones.email' => 'El email de notificaciones debe ser un email válido.',
        ];
    }
}
