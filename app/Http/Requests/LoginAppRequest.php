<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo_modular' => 'required_without:codigo|string',
            'codigo' => 'required_without:codigo_modular|string', // Alias
            'password' => 'required|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_modular.required_without' => 'El código modular es obligatorio',
            'codigo.required_without' => 'El código modular es obligatorio',
            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos :min caracteres',
        ];
    }
}