<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('usuario_app') ?? $this->route('id');

        return [
            'codigo_modular_docente' => 'sometimes|string|max:20|unique:usuarios_app,codigo_modular_docente,' . $id,

            'apellido_paterno' => 'sometimes|string|max:100',
            'apellido_materno' => 'sometimes|string|max:100',
            'nombres' => 'sometimes|string|max:100',
            'sexo' => 'sometimes|in:M,F',

            'estado' => 'sometimes|in:ACTIVO,INACTIVO',
            'cargo' => 'sometimes|nullable|string|max:50',

            'password' => 'nullable|string|min:6',
            'activo' => 'nullable|boolean',

            // Si envían instituciones, el controller hace sync() y reemplaza el set
            'instituciones' => 'nullable|array',
            'instituciones.*.institucion_id' => 'required_with:instituciones|integer|exists:instituciones,id',
            'instituciones.*.estado' => 'nullable|in:ACTIVO,INACTIVO',
            'instituciones.*.fecha_inicio' => 'nullable|date',
            'instituciones.*.fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ];
    }
}
