<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio',
            'name.max' => 'El nombre no debe exceder los 255 caracteres',
            'email.required' => 'El email es obligatorio',
            'email.email' => 'El email debe tener un formato válido',
            'email.max' => 'El email no debe exceder los 255 caracteres',
            'password.required' => 'El password es obligatorio',
            'password.min' => 'El password debe tener al menos 6 caracteres',
        ];
    }
}
