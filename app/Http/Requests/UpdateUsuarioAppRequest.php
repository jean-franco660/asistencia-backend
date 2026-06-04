<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UsuarioApp;
use Illuminate\Validation\Rule;

/**
 * Valida los datos para actualizar un usuario de la aplicación móvil (docente).
 * Todos los campos son opcionales ('sometimes'), excepto cuando se envía la contraseña,
 * en cuyo caso la confirmación pasa a ser obligatoria.
 * Si se envía el array 'asignaciones', se reemplaza la lista completa de asignaciones del usuario.
 * La autorización se delega a las policies del controlador.
 */
class UpdateUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Retorna las reglas de validación para la actualización del usuario de la aplicación móvil.
     * Los campos únicos excluyen el propio registro del usuario para evitar falsos conflictos.
     * La contraseña es opcional en la actualización; si se envía, requiere confirmación.
     * Si se envía 'asignaciones', se valida como array completo que reemplazará las asignaciones actuales.
     */
    public function rules(): array
    {
        $userId = $this->route('usuario_app') ?? $this->route('id');

        return [
            'codigo_modular' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('usuarios_app', 'codigo_modular')->ignore($userId)
            ],

            'dni' => [
                'sometimes',
                'string',
                'max:15',
                Rule::unique('usuarios_app', 'dni')->ignore($userId)
            ],

            'apellido_paterno' => 'sometimes|string|max:100',
            'apellido_materno' => 'sometimes|string|max:100',
            'nombres' => 'sometimes|string|max:100',
            'sexo' => ['sometimes', Rule::in(UsuarioApp::getSexosDisponibles())],

            'password' => 'nullable|string|min:8|confirmed',
            'password_confirmation' => 'required_with:password|same:password', // Obligatoria solo si se envía 'password'

            'acceso_habilitado' => 'sometimes|boolean',

            'asignaciones' => 'sometimes|array', // Si se envía, reemplaza todas las asignaciones del usuario
            'asignaciones.*.institucion_id' => 'required|exists:instituciones,id|distinct',
            'asignaciones.*.horario_institucion_id' => 'nullable|exists:horarios_institucion,id',
            'asignaciones.*.cargo' => 'required|string|max:50',
            'asignaciones.*.estado' => 'nullable|in:ACTIVO,INACTIVO',
            'asignaciones.*.fecha_inicio' => 'nullable|date',
            'asignaciones.*.fecha_fin' => 'nullable|date|after:asignaciones.*.fecha_inicio',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_modular.unique' => 'Este código modular ya está registrado',
            'codigo_modular.max' => 'El código modular no puede exceder :max caracteres',

            'dni.unique' => 'Este DNI ya está registrado',
            'dni.max' => 'El DNI no puede exceder :max caracteres',

            'apellido_paterno.max' => 'El apellido paterno no puede exceder :max caracteres',
            'apellido_materno.max' => 'El apellido materno no puede exceder :max caracteres',
            'nombres.max' => 'Los nombres no pueden exceder :max caracteres',
            'sexo.in' => 'El sexo debe ser M (Masculino) o F (Femenino)',

            'password.min' => 'La contraseña debe tener al menos :min caracteres',
            'password.confirmed' => 'La confirmación de contraseña no coincide',
            'password_confirmation.required_with' => 'Debe confirmar la contraseña',
            'password_confirmation.same' => 'Las contraseñas no coinciden',

            'asignaciones.array' => 'Las asignaciones deben ser un arreglo',

            'asignaciones.*.institucion_id.required' => 'La institución es obligatoria',
            'asignaciones.*.institucion_id.exists' => 'La institución seleccionada no existe',
            'asignaciones.*.institucion_id.distinct' => 'No puede asignar la misma institución más de una vez a un mismo usuario.',

            'asignaciones.*.horario_institucion_id.exists' => 'El horario seleccionado no existe',

            'asignaciones.*.cargo.required' => 'El cargo es obligatorio',
            'asignaciones.*.cargo.max' => 'El cargo no puede exceder :max caracteres',

            'asignaciones.*.estado.in' => 'El estado debe ser ACTIVO o INACTIVO',

            'asignaciones.*.fecha_fin.after' => 'La fecha de fin debe ser posterior a la fecha de inicio',
        ];
    }

    /**
     * Normaliza el campo 'codigo' al nombre canónico 'codigo_modular' antes de la validación,
     * para mantener compatibilidad con versiones anteriores del cliente móvil.
     */
    protected function prepareForValidation()
    {
        // Normalizar código modular si llega con el nombre alternativo 'codigo'
        if ($this->has('codigo') && !$this->has('codigo_modular')) {
            $this->merge([
                'codigo_modular' => $this->codigo
            ]);
        }
    }
}