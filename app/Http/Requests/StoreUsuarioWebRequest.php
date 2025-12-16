<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->rol === 'administrador';
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:100',
            'email' => 'required|email|unique:usuarios_web,email',
            'password' => 'required|string|min:6',
            'rol' => 'required|in:administrador,supervisor',
            'estado' => 'required|in:pendiente,autorizado,rechazado',
        ];
    }
}
