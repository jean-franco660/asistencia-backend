<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // login público
    }

    public function rules(): array
    {
        return [
            'email'    => 'required|email',
            'password' => 'required|string|min:8'
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email'    => 'Formato de correo inválido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'Minimo 8 caracteres.',
        ];
    }
}
