<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUsuarioWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->rol, ['super_admin', 'administrador']);
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:usuarios_web,email,' . $this->id,
            'password' => 'sometimes|string|min:6',
            'rol' => 'sometimes|in:administrador,supervisor',
            'estado' => 'sometimes|in:pendiente,autorizado,rechazado',
        ];
    }
}
