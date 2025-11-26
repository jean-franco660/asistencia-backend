<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->rol, ['admin', 'director']);
    }

    public function rules(): array
    {
        return [
            'nombre'   => 'required|string|max:150',
            'codigo'   => 'required|string|max:50|unique:usuarios_app,codigo',
            'password' => 'required|string|min:6',
            'activo'   => 'required|boolean',
        ];
    }
}
