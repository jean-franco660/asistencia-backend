<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida las credenciales de inicio de sesión para usuarios del panel web.
 * Verifica que el correo tenga formato válido y que la contraseña cumpla
 * la longitud mínima. El acceso es público; no requiere autenticación previa.
 */
class LoginWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Formato de correo inválido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'Minimo 8 caracteres.',
        ];
    }
}
