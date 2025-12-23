<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UsuarioApp;
use Illuminate\Validation\Rule;

class StoreUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización se maneja en el controller con policies
    }

    public function rules(): array
    {
        return [
            // Identificación (según migración real)
            'codigo_modular' => 'required|string|max:20|unique:usuarios_app,codigo_modular',
            'dni' => 'required|string|max:15|unique:usuarios_app,dni',

            // Datos personales
            'apellido_paterno' => 'required|string|max:100',
            'apellido_materno' => 'required|string|max:100',
            'nombres' => 'required|string|max:100',
            'sexo' => ['nullable', Rule::in(UsuarioApp::getSexosDisponibles())], // M, F

            // Credenciales
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|same:password',

            // Acceso (según migración: acceso_habilitado, no "activo")
            'acceso_habilitado' => 'nullable|boolean',

            // Asignaciones (tabla usuario_app_institucion)
            // IMPORTANTE: El cargo NO va en usuarios_app, va en la asignación
            'asignaciones' => 'required|array|min:1',
            'asignaciones.*.institucion_id' => 'required|exists:instituciones,id',
            'asignaciones.*.horario_institucion_id' => 'nullable|exists:horarios_institucion,id', // ✅ Opcional
            'asignaciones.*.cargo' => 'required|string|max:50', // Campo libre
            'asignaciones.*.estado' => 'nullable|in:ACTIVO,INACTIVO',
            'asignaciones.*.fecha_inicio' => 'nullable|date',
            'asignaciones.*.fecha_fin' => 'nullable|date|after:asignaciones.*.fecha_inicio',
        ];
    }

    public function messages(): array
    {
        return [
            // Código modular
            'codigo_modular.required' => 'El código modular es obligatorio',
            'codigo_modular.unique' => 'Este código modular ya está registrado',
            'codigo_modular.max' => 'El código modular no puede exceder :max caracteres',

            // DNI
            'dni.required' => 'El DNI es obligatorio',
            'dni.unique' => 'Este DNI ya está registrado',
            'dni.max' => 'El DNI no puede exceder :max caracteres',

            // Datos personales
            'apellido_paterno.required' => 'El apellido paterno es obligatorio',
            'apellido_paterno.max' => 'El apellido paterno no puede exceder :max caracteres',

            'apellido_materno.required' => 'El apellido materno es obligatorio',
            'apellido_materno.max' => 'El apellido materno no puede exceder :max caracteres',

            'nombres.required' => 'Los nombres son obligatorios',
            'nombres.max' => 'Los nombres no pueden exceder :max caracteres',

            // Sexo
            'sexo.in' => 'El sexo debe ser M (Masculino) o F (Femenino)',

            // Contraseña
            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos :min caracteres',
            'password.confirmed' => 'La confirmación de contraseña no coincide',
            'password_confirmation.required' => 'Debe confirmar la contraseña',
            'password_confirmation.same' => 'Las contraseñas no coinciden',

            // Asignaciones
            'asignaciones.required' => 'Debe asignar al menos una institución',
            'asignaciones.min' => 'Debe asignar al menos una institución',
            'asignaciones.array' => 'Las asignaciones deben ser un arreglo',

            'asignaciones.*.institucion_id.required' => 'La institución es obligatoria',
            'asignaciones.*.institucion_id.exists' => 'La institución seleccionada no existe',

            'asignaciones.*.horario_institucion_id.exists' => 'El horario seleccionado no existe',

            'asignaciones.*.cargo.required' => 'El cargo es obligatorio',
            'asignaciones.*.cargo.max' => 'El cargo no puede exceder :max caracteres',

            'asignaciones.*.estado.in' => 'El estado debe ser ACTIVO o INACTIVO',

            'asignaciones.*.fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',

            'asignaciones.*.fecha_fin.date' => 'La fecha de fin debe ser una fecha válida',
            'asignaciones.*.fecha_fin.after' => 'La fecha de fin debe ser posterior a la fecha de inicio',
        ];
    }

    /**
     * Prepara los datos para validación
     * Normaliza el código modular y otros campos
     */
    protected function prepareForValidation()
    {
        // Normalizar código modular (si viene con alias)
        if ($this->has('codigo') && !$this->has('codigo_modular')) {
            $this->merge([
                'codigo_modular' => $this->codigo
            ]);
        }

        // Valores por defecto
        $this->merge([
            'acceso_habilitado' => $this->input('acceso_habilitado', true),
        ]);
    }
}