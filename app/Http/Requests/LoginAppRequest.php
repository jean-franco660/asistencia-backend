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
            // Aceptar código modular (campo `codigo_modular` o `codigo`) o `dni`
            // Permitir códigos alfanuméricos como DOC2025001
            'codigo_modular' => ['required_without_all:codigo,dni','string','regex:/^[A-Za-z0-9]+$/'],
            'codigo' => ['required_without_all:codigo_modular,dni','string','regex:/^[A-Za-z0-9]+$/'], // Nombre alternativo aceptado por compatibilidad con clientes anteriores
            'dni' => 'required_without_all:codigo_modular,codigo|string',
            'password' => 'required|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_modular.required_without_all' => 'El código modular o el DNI es obligatorio',
            'codigo.required_without_all' => 'El código modular o el DNI es obligatorio',
            'codigo_modular.regex' => 'El código modular sólo puede contener letras y números (ej: DOC2025001)',
            'codigo.regex' => 'El código sólo puede contener letras y números (ej: DOC2025001)',
            'dni.required_without_all' => 'El DNI o el código modular es obligatorio',
            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos :min caracteres',
        ];
    }
}
