<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida las credenciales de inicio de sesión para usuarios de la aplicación móvil.
 * Acepta el código modular del docente bajo dos nombres de campo alternativos
 * para mantener compatibilidad con distintas versiones del cliente.
 * El acceso es público; no requiere autenticación previa.
 */
class LoginAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Define las reglas de validación para el inicio de sesión móvil.
     * El código modular puede enviarse como 'codigo_modular' o como 'codigo';
     * al menos uno de los dos campos es obligatorio.
     */
    public function rules(): array
    {
        return [
            'codigo_modular' => 'required_without:codigo|string',
            'codigo' => 'required_without:codigo_modular|string', // Nombre alternativo aceptado por compatibilidad con clientes anteriores
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