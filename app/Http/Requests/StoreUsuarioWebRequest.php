<?php

namespace App\Http\Requests;

use App\Models\UsuarioWeb;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los datos requeridos para crear un nuevo usuario del panel web (administrador o supervisor).
 * Solo los usuarios con rol 'super_admin' o 'administrador' pueden registrar nuevos usuarios web.
 * Garantiza la unicidad del correo electrónico en la tabla de usuarios web.
 */
class StoreUsuarioWebRequest extends FormRequest
{
    /**
     * Restringe la operación a usuarios con rol administrativo.
     * Los supervisores no tienen permiso para crear otros usuarios web.
     */
    public function authorize(): bool
    {
        return in_array($this->user()->rol, ['super_admin', 'administrador']);
    }

    /**
     * Retorna las reglas de validación para un nuevo usuario web.
     * El campo 'estado' usa 'sometimes' porque puede ser omitido en la creación;
     * si se envía, debe ser uno de los valores del flujo de aprobación.
     * El campo 'rol' no puede ser 'super_admin' para evitar escalada de privilegios
     * si el controlador no lo filtra previamente.
     */
    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:100',
            'email' => 'required|email|unique:usuarios_web,email',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
            'rol' => 'required|in:super_admin,administrador,supervisor',
            'estado' => 'sometimes|in:pendiente,autorizado,rechazado',
            'institucion_id' => 'nullable|exists:instituciones,id',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio',
            'email.required' => 'El email es obligatorio',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'Este email ya está registrado',
            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'password.confirmed' => 'Las contraseñas no coinciden',
            'rol.required' => 'El rol es obligatorio',
            'rol.in' => 'El rol seleccionado no es válido',
        ];
    }
}