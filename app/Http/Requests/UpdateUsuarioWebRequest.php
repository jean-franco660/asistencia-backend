<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los datos para actualizar un usuario del panel web.
 * Solo los usuarios con rol 'super_admin' o 'administrador' pueden modificar usuarios web.
 * Todos los campos son opcionales ('sometimes'); solo se validan los que se envíen.
 */
class UpdateUsuarioWebRequest extends FormRequest
{
    /**
     * Restringe la operación a usuarios con rol administrativo.
     * Los supervisores no pueden modificar perfiles de otros usuarios web.
     */
    public function authorize(): bool
    {
        return in_array($this->user()->rol, ['super_admin', 'administrador']);
    }

    /**
     * Retorna las reglas de validación para la actualización del usuario web.
     * La unicidad del email excluye el registro actual usando el parámetro de ruta 'id'
     * para evitar un falso conflicto al enviar el mismo correo sin modificarlo.
     */
    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:usuarios_web,email,' . $this->id,
            'password' => 'sometimes|string|min:8',
            'rol' => 'sometimes|in:administrador,supervisor',
            'estado' => 'sometimes|in:pendiente,autorizado,rechazado',
        ];
    }
}
