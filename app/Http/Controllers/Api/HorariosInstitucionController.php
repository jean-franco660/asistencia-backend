<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HorarioInstitucion;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class HorariosInstitucionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Definición de rangos horarios por turno
     * Solo 3 turnos permitidos: mañana, tarde y noche
     */
    protected $rangosTurnos = [
        'mañana' => [
            'min' => '05:00',
            'max' => '13:00',
        ],
        'tarde' => [
            'min' => '13:00',
            'max' => '19:00',
        ],
        'noche' => [
            'min' => '19:00',
            'max' => '23:59',
        ],
    ];

    public function index(Request $request)
    {
        $query = HorarioInstitucion::query();

        if ($request->has('institucion_id') && $request->institucion_id) {
            $query->where('institucion_id', $request->institucion_id);

            if ($request->user()->rol === 'supervisor') {
                $instituciones = $request->user()->instituciones->pluck('id');
                if (!$instituciones->contains($request->institucion_id)) {
                    return response()->json(['error' => 'No autorizado'], 403);
                }
            }
        } else {
            if ($request->user()->rol === 'supervisor') {
                $instituciones = $request->user()->instituciones->pluck('id');

                if ($instituciones->count() === 1) {
                    $query->where('institucion_id', $instituciones->first());
                } else {
                    $query->whereIn('institucion_id', $instituciones);
                }
            }
        }

        return $query->orderBy('hora_entrada')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'institucion_id' => 'required|exists:instituciones,id',
            'nombre_turno' => 'required|string|max:50',
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i|after:hora_entrada',
            'tolerancia_minutos' => 'required|integer|min:0|max:60',
            'dias_semana' => 'required|array|min:1',
            'dias_semana.*' => 'in:L,M,X,J,V,S,D',
        ]);

        // Si es supervisor → validar institución asignada
        if ($request->user()->rol === 'supervisor') {
            if (!$request->user()->instituciones->pluck('id')->contains($request->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }

        // Validar coherencia entre nombre del turno y horarios
        $this->validarCoherenciaTurno(
            $request->nombre_turno,
            $request->hora_entrada,
            $request->hora_salida
        );

        // Autorización por rol
        $this->authorize('create', HorarioInstitucion::class);

        $horario = HorarioInstitucion::create([
            'institucion_id' => $request->institucion_id,
            'nombre_turno' => $request->nombre_turno,
            'hora_entrada' => $request->hora_entrada,
            'hora_salida' => $request->hora_salida,
            'tolerancia_minutos' => $request->tolerancia_minutos,
            'dias_semana' => $request->dias_semana,
            'activo' => true,
        ]);

        return response()->json($horario, 201);
    }

    public function update(Request $request, $id)
    {
        $horario = HorarioInstitucion::findOrFail($id);

        if ($request->user()->rol === 'supervisor') {
            if (!$request->user()->instituciones->pluck('id')->contains($horario->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }

        $this->authorize('update', $horario);

        $request->validate([
            'nombre_turno' => 'sometimes|string|max:50',
            'hora_entrada' => 'sometimes|date_format:H:i',
            'hora_salida' => 'sometimes|date_format:H:i|after:hora_entrada',
            'tolerancia_minutos' => 'sometimes|integer|min:0|max:60',
            'activo' => 'sometimes|boolean',
            'dias_semana' => 'sometimes|array|min:1',
            'dias_semana.*' => 'in:L,M,X,J,V,S,D',
        ]);

        // Validar coherencia si se actualizan turno u horas
        if ($request->has(['nombre_turno', 'hora_entrada', 'hora_salida'])) {
            $this->validarCoherenciaTurno(
                $request->nombre_turno ?? $horario->nombre_turno,
                $request->hora_entrada ?? $horario->hora_entrada,
                $request->hora_salida ?? $horario->hora_salida
            );
        }

        $horario->update($request->only([
            'nombre_turno',
            'hora_entrada',
            'hora_salida',
            'tolerancia_minutos',
            'dias_semana',
            'activo',
        ]));

        return response()->json($horario);
    }

    public function destroy(Request $request, $id)
    {
        $horario = HorarioInstitucion::findOrFail($id);

        if ($request->user()->rol === 'supervisor') {
            if (!$request->user()->instituciones->pluck('id')->contains($horario->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }

        $this->authorize('delete', $horario);

        $horario->delete();

        return response()->json(['message' => 'Horario eliminado']);
    }

    /**
     * Valida que las horas sean coherentes con el tipo de turno
     */
    protected function validarCoherenciaTurno(string $nombreTurno, string $horaEntrada, string $horaSalida): void
    {
        // Normalizar nombre del turno (minúsculas, sin espacios)
        $turnoNormalizado = strtolower(trim($nombreTurno));

        // Buscar si el nombre del turno contiene alguna palabra clave
        $turnoDetectado = null;
        foreach (array_keys($this->rangosTurnos) as $tipo) {
            if (str_contains($turnoNormalizado, $tipo)) {
                $turnoDetectado = $tipo;
                break;
            }
        }

        // Si no se detectó un turno conocido, no validar
        if (!$turnoDetectado) {
            return;
        }

        $rango = $this->rangosTurnos[$turnoDetectado];
        
        $entrada = Carbon::createFromFormat('H:i', $horaEntrada);
        $salida = Carbon::createFromFormat('H:i', $horaSalida);
        $min = Carbon::createFromFormat('H:i', $rango['min']);
        $max = Carbon::createFromFormat('H:i', $rango['max']);

        // Validar que la hora de entrada esté dentro del rango
        if ($entrada->lt($min) || $entrada->gt($max)) {
            throw ValidationException::withMessages([
                'hora_entrada' => [
                    "La hora de entrada ({$horaEntrada}) no es coherente con el turno '{$nombreTurno}'. " .
                    "Debe estar entre {$rango['min']} y {$rango['max']}."
                ]
            ]);
        }

        // Validar que la hora de salida esté dentro del rango (con tolerancia)
        // Permitir que la salida se extienda 1 hora después del max
        $maxConTolerancia = $max->copy()->addHour();
        
        if ($salida->lt($min) || $salida->gt($maxConTolerancia)) {
            throw ValidationException::withMessages([
                'hora_salida' => [
                    "La hora de salida ({$horaSalida}) no es coherente con el turno '{$nombreTurno}'. " .
                    "Debe estar entre {$rango['min']} y " . $maxConTolerancia->format('H:i') . "."
                ]
            ]);
        }
    }


}