<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los datos necesarios para crear un horario en una institución educativa.
 * Gestiona los turnos mañana, tarde y noche, con validación condicional
 * en la hora de salida para el turno noche, donde el cruce de medianoche
 * hace inválida la regla 'after:hora_entrada'.
 * Accesible por cualquier usuario autenticado con permisos sobre horarios.
 */
class StoreHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Retorna las reglas de validación para el horario.
     * Para el turno noche se omite la regla 'after:hora_entrada' en hora_salida,
     * ya que ese turno puede cruzar la medianoche y la comparación directa de horas
     * produciría un falso negativo.
     */
    public function rules(): array
    {
        $rules = [
            'institucion_id' => 'required|exists:instituciones,id',
            'turno' => 'required|in:mañana,tarde,noche',
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i',
            'tolerancia_entrada' => 'nullable|integer|min:0|max:60',
            'tolerancia_salida' => 'nullable|integer|min:0|max:60',
        ];

        // El turno noche puede cruzar la medianoche, por lo que no se aplica 'after:hora_entrada'
        if ($this->input('turno') !== 'noche') {
            $rules['hora_salida'] .= '|after:hora_entrada';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'institucion_id.required' => 'La institución es obligatoria',
            'institucion_id.exists' => 'La institución seleccionada no existe',
            'turno.required' => 'El turno es obligatorio',
            'turno.in' => 'El turno debe ser: mañana, tarde o noche',
            'hora_entrada.required' => 'La hora de entrada es obligatoria',
            'hora_entrada.date_format' => 'Formato de hora de entrada inválido',
            'hora_salida.required' => 'La hora de salida es obligatoria',
            'hora_salida.date_format' => 'Formato de hora de salida inválido',
            'hora_salida.after' => 'La hora de salida debe ser posterior a la hora de entrada',
        ];
    }

}