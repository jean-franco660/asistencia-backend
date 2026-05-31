<?php

namespace App\Http\Requests;

use App\Models\UsuarioWeb;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        //  Permitir super_admin y admin
        return in_array($this->user()->rol, ['super_admin', 'administrador']);
    }

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