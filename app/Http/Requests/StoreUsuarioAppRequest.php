<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UsuarioApp;
use Illuminate\Validation\Rule;

/**
 * Valida los datos requeridos para crear un nuevo usuario de la aplicación móvil (docente).
 * Exige al menos una asignación institucional con cargo, ya que el cargo no pertenece
 * al usuario sino a la relación con la institución.
 * La autorización se delega a las policies del controlador; esta clase siempre autoriza.
 */
class StoreUsuarioAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización se maneja en el controlador mediante policies
        return true;
    }

    /**
     * Retorna las reglas de validación para el nuevo usuario de la aplicación móvil.
     * El campo 'asignaciones' es obligatorio y debe tener al menos un elemento,
     * ya que un docente sin institución asignada no puede registrar asistencia.
     * El campo 'cargo' se ubica en la asignación, no en el usuario, conforme al diseño del modelo.
     */
    public function rules(): array
    {
        return [
            'codigo_modular' => 'required|string|max:20|unique:usuarios_app,codigo_modular',
            'dni' => 'required|string|max:15|unique:usuarios_app,dni',

            'apellido_paterno' => 'required|string|max:100',
            'apellido_materno' => 'required|string|max:100',
            'nombres' => 'required|string|max:100',
            'sexo' => ['nullable', Rule::in(UsuarioApp::getSexosDisponibles())], // Valores posibles: M, F

            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|same:password',

            'acceso_habilitado' => 'nullable|boolean',

            'asignaciones' => 'required|array|min:1',
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
            'codigo_modular.required' => 'El código modular es obligatorio',
            'codigo_modular.unique' => 'Este código modular ya está registrado',
            'codigo_modular.max' => 'El código modular no puede exceder :max caracteres',

            'dni.required' => 'El DNI es obligatorio',
            'dni.unique' => 'Este DNI ya está registrado',
            'dni.max' => 'El DNI no puede exceder :max caracteres',

            'apellido_paterno.required' => 'El apellido paterno es obligatorio',
            'apellido_paterno.max' => 'El apellido paterno no puede exceder :max caracteres',

            'apellido_materno.required' => 'El apellido materno es obligatorio',
            'apellido_materno.max' => 'El apellido materno no puede exceder :max caracteres',

            'nombres.required' => 'Los nombres son obligatorios',
            'nombres.max' => 'Los nombres no pueden exceder :max caracteres',

            'sexo.in' => 'El sexo debe ser M (Masculino) o F (Femenino)',

            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos :min caracteres',
            'password.confirmed' => 'La confirmación de contraseña no coincide',
            'password_confirmation.required' => 'Debe confirmar la contraseña',
            'password_confirmation.same' => 'Las contraseñas no coinciden',

            'asignaciones.required' => 'Debe asignar al menos una institución',
            'asignaciones.min' => 'Debe asignar al menos una institución',
            'asignaciones.array' => 'Las asignaciones deben ser un arreglo',

            'asignaciones.*.institucion_id.required' => 'La institución es obligatoria',
            'asignaciones.*.institucion_id.exists' => 'La institución seleccionada no existe',
            'asignaciones.*.institucion_id.distinct' => 'No puede asignar la misma institución más de una vez a un mismo usuario.',

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
     * Normaliza los datos de entrada antes de la validación.
     * Si el cliente envía el campo 'codigo' en lugar de 'codigo_modular', lo mapea
     * al nombre canónico para mantener compatibilidad con versiones anteriores del cliente.
     * También establece 'acceso_habilitado' como verdadero por defecto si no se envía.
     */
    protected function prepareForValidation()
    {
        // Normalizar código modular si llega con el nombre alternativo 'codigo'
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