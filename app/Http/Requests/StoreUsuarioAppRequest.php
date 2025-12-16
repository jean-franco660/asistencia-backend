<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo_modular_docente' => 'required|string|max:20|unique:usuarios_app,codigo_modular_docente',

            'apellido_paterno' => 'required|string|max:100',
            'apellido_materno' => 'required|string|max:100',
            'nombres' => 'required|string|max:100',
            'sexo' => 'required|in:M,F',

            'estado' => 'nullable|in:ACTIVO,INACTIVO',
            'cargo' => 'nullable|string|max:50',

            'password' => 'required|string|min:6',
            'activo' => 'nullable|boolean',

            // N:M instituciones (por pivote)
            'instituciones' => 'nullable|array',
            'instituciones.*.institucion_id' => 'required_with:instituciones|integer|exists:instituciones,id',
            'instituciones.*.estado' => 'nullable|in:ACTIVO,INACTIVO',
            'instituciones.*.fecha_inicio' => 'nullable|date',
            'instituciones.*.fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_modular_docente.required' => 'El código es obligatorio',
            'codigo_modular_docente.unique' => 'Este código ya está registrado',

            'apellido_paterno.required' => 'El apellido paterno es obligatorio',
            'apellido_materno.required' => 'El apellido materno es obligatorio',
            'nombres.required' => 'Los nombres son obligatorios',

            'sexo.required' => 'El sexo es obligatorio',
            'sexo.in' => 'El sexo debe ser M o F',

            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres',

            'instituciones.*.institucion_id.required_with' => 'Cada institución debe incluir institucion_id',
            'instituciones.*.institucion_id.exists' => 'Una de las instituciones seleccionadas no existe',
            'instituciones.*.fecha_fin.after_or_equal' => 'La fecha_fin no puede ser anterior a la fecha_inicio',
        ];
    }
}
