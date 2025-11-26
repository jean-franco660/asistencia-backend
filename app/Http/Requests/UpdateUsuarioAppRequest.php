<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->rol, ['admin', 'director']);
    }

    public function rules(): array
    {
        return [
            'nombre'   => 'sometimes|string|max:150',
            'codigo'   => 'sometimes|string|max:50|unique:usuarios_app,codigo,' . $this->id,
            'password' => 'sometimes|string|min:6',
            'activo'   => 'sometimes|boolean',
        ];
    }
}
