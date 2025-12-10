<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institucion_id' => 'required|exists:instituciones,id',
            'turno' => 'required|in:mañana,tarde,noche',
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i|after:hora_entrada',
            'tolerancia_entrada' => 'nullable|integer|min:0|max:60',
            'tolerancia_salida' => 'nullable|integer|min:0|max:60',
        ];
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

    /**
     * Validación personalizada después de las reglas básicas
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $turno = $this->input('turno');
            $hora_entrada = $this->input('hora_entrada');
            $hora_salida = $this->input('hora_salida');

            // Definir rangos de horas por turno
            $rangos = [
                'mañana' => ['inicio' => '05:00', 'fin' => '13:00'],
                'tarde' => ['inicio' => '13:00', 'fin' => '19:00'],
                'noche' => ['inicio' => '19:00', 'fin' => '23:59'],
            ];

            if (!isset($rangos[$turno])) {
                return;
            }

            $rango = $rangos[$turno];

            // Validar hora de entrada
            if ($hora_entrada && ($hora_entrada < $rango['inicio'] || $hora_entrada > $rango['fin'])) {
                $validator->errors()->add(
                    'hora_entrada',
                    "La hora de entrada debe estar entre {$rango['inicio']} y {$rango['fin']} para el turno {$turno}"
                );
            }

            // Validar hora de salida
            if ($hora_salida && ($hora_salida < $rango['inicio'] || $hora_salida > $rango['fin'])) {
                $validator->errors()->add(
                    'hora_salida',
                    "La hora de salida debe estar entre {$rango['inicio']} y {$rango['fin']} para el turno {$turno}"
                );
            }
        });
    }
}