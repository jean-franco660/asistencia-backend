<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UsuarioApp;
use Illuminate\Validation\Rule;

class UpdateUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('usuario_app') ?? $this->route('id');

        return [
            // Identificación
            'codigo_modular' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('usuarios_app', 'codigo_modular')->ignore($userId)
            ],

            // Datos personales
            'apellido_paterno' => 'sometimes|string|max:100',
            'apellido_materno' => 'sometimes|string|max:100',
            'nombres' => 'sometimes|string|max:100',
            'sexo' => ['sometimes', Rule::in(UsuarioApp::getSexosDisponibles())],

            // Credenciales (opcional en update)
            'password' => 'nullable|string|min:8|confirmed',
            'password_confirmation' => 'required_with:password|same:password',

            // Acceso
            'acceso_habilitado' => 'sometimes|boolean',

            // Asignaciones (opcional, si se envía se reemplaza todo)
            'asignaciones' => 'sometimes|array',
            'asignaciones.*.institucion_id' => 'required|exists:instituciones,id',
            'asignaciones.*.horario_institucion_id' => 'required|exists:horarios_institucion,id',
            'asignaciones.*.cargo' => 'required|string|max:50',
            'asignaciones.*.estado' => 'nullable|in:ACTIVO,INACTIVO',
            'asignaciones.*.fecha_inicio' => 'nullable|date',
            'asignaciones.*.fecha_fin' => 'nullable|date|after:asignaciones.*.fecha_inicio',
        ];
    }

    public function messages(): array
    {
        return [
            // Código modular
            'codigo_modular.unique' => 'Este código modular ya está registrado',
            'codigo_modular.max' => 'El código modular no puede exceder :max caracteres',

            // Datos personales
            'apellido_paterno.max' => 'El apellido paterno no puede exceder :max caracteres',
            'apellido_materno.max' => 'El apellido materno no puede exceder :max caracteres',
            'nombres.max' => 'Los nombres no pueden exceder :max caracteres',
            'sexo.in' => 'El sexo debe ser M (Masculino) o F (Femenino)',

            // Contraseña
            'password.min' => 'La contraseña debe tener al menos :min caracteres',
            'password.confirmed' => 'La confirmación de contraseña no coincide',
            'password_confirmation.required_with' => 'Debe confirmar la contraseña',
            'password_confirmation.same' => 'Las contraseñas no coinciden',

            // Asignaciones
            'asignaciones.array' => 'Las asignaciones deben ser un arreglo',
            
            'asignaciones.*.institucion_id.required' => 'La institución es obligatoria',
            'asignaciones.*.institucion_id.exists' => 'La institución seleccionada no existe',
            
            'asignaciones.*.horario_institucion_id.required' => 'El horario es obligatorio',
            'asignaciones.*.horario_institucion_id.exists' => 'El horario seleccionado no existe',
            
            'asignaciones.*.cargo.required' => 'El cargo es obligatorio',
            'asignaciones.*.cargo.max' => 'El cargo no puede exceder :max caracteres',
            
            'asignaciones.*.estado.in' => 'El estado debe ser ACTIVO o INACTIVO',
            
            'asignaciones.*.fecha_fin.after' => 'La fecha de fin debe ser posterior a la fecha de inicio',
        ];
    }

    /**
     * Prepara los datos para validación
     */
    protected function prepareForValidation()
    {
        // Normalizar código modular (si viene con alias)
        if ($this->has('codigo') && !$this->has('codigo_modular')) {
            $this->merge([
                'codigo_modular' => $this->codigo
            ]);
        }
    }
}