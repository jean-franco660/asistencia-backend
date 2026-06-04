<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\HorarioInstitucion;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

/**
 * Gestiona los horarios de las instituciones educativas.
 *
 * Accesible para administradores y supervisores. Los supervisores solo pueden
 * operar sobre horarios de sus instituciones asignadas. Al crear un horario,
 * se asigna automáticamente a los usuarios activos de la institución que
 * no tienen horario configurado.
 */
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

    /**
     * Lista los horarios de institución accesibles según el rol del usuario.
     *
     * Si se proporciona institucion_id, filtra por esa institución y valida que
     * el supervisor tenga acceso a ella. Si no se especifica, los supervisores
     * ven los horarios de todas sus instituciones asignadas. Los administradores
     * ven todos los horarios sin restricción.
     */
    public function index(Request $request)
    {
        $query = HorarioInstitucion::query();

        if ($request->has('institucion_id') && $request->institucion_id) {
            $query->where('institucion_id', $request->institucion_id);

            if ($request->user()->esSupervisor()) {
                $instituciones = $request->user()->instituciones->pluck('id');
                if (!$instituciones->contains($request->institucion_id)) {
                    return response()->json(['error' => 'No autorizado'], 403);
                }
            }
        } else {
            if ($request->user()->esSupervisor()) {
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

    /**
     * Crea un nuevo horario para una institución.
     *
     * Los supervisores solo pueden crear horarios en instituciones que tienen asignadas.
     * Valida la coherencia entre el nombre del turno y las horas definidas.
     * Tras la creación, asigna automáticamente el horario a los usuarios activos
     * de la institución que aún no tienen horario configurado.
     */
    public function store(Request $request)
    {
        $request->validate([
            'institucion_id' => 'required|exists:instituciones,id',
            'nombre_turno' => 'required|string|max:50',
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i|after:hora_entrada',
            'tolerancia_entrada_minutos' => 'required|integer|min:0|max:60',
            'tolerancia_salida_minutos' => 'required|integer|min:0|max:60',
            'dias_semana' => 'required|array|min:1',
            'dias_semana.*' => 'in:L,M,X,J,V,S,D',
        ]);

        // Los supervisores solo pueden crear horarios en sus instituciones asignadas
        if ($request->user()->esSupervisor()) {
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
            'tolerancia_entrada_minutos' => $request->tolerancia_entrada_minutos,
            'tolerancia_salida_minutos' => $request->tolerancia_salida_minutos,
            'dias_semana' => $request->dias_semana,
            'activo' => true,
        ]);

        // Asigna el horario recién creado a usuarios activos sin horario en esta institución
        $this->asignarHorarioAUsuariosSinHorario($request->institucion_id, $horario->id);

        return response()->json([
            'success' => true,
            'message' => 'Horario creado correctamente',
            'data' => $horario,
        ], 201);
    }

    /**
     *  Asigna un horario a todos los usuarios de una institución que no tienen horario
     */
    protected function asignarHorarioAUsuariosSinHorario(int $institucionId, int $horarioId): void
    {
        // Buscar todas las asignaciones activas sin horario para esta institución
        $asignacionesSinHorario = \App\Models\UsuarioAppInstitucion::where('institucion_id', $institucionId)
            ->whereNull('horario_institucion_id')
            ->where('estado', \App\Models\UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->get();

        $count = $asignacionesSinHorario->count();

        foreach ($asignacionesSinHorario as $asignacion) {
            $asignacion->update(['horario_institucion_id' => $horarioId]);
        }

        if ($count > 0) {
            \Log::info("Horario {$horarioId} asignado automáticamente a {$count} usuario(s) de la institución {$institucionId}");
        }
    }

    /**
     * Actualiza los datos de un horario existente.
     *
     * Los supervisores solo pueden actualizar horarios de sus instituciones asignadas.
     * Si se modifican el turno o las horas, se valida la coherencia entre ellos.
     */
    public function update(Request $request, $id)
    {
        $horario = HorarioInstitucion::findOrFail($id);

        if ($request->user()->esSupervisor()) {
            if (!$request->user()->instituciones->pluck('id')->contains($horario->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }

        $this->authorize('update', $horario);

        $request->validate([
            'nombre_turno' => 'sometimes|string|max:50',
            'hora_entrada' => 'sometimes|date_format:H:i',
            'hora_salida' => 'sometimes|date_format:H:i|after:hora_entrada',
            'tolerancia_entrada_minutos' => 'sometimes|integer|min:0|max:60',
            'tolerancia_salida_minutos' => 'sometimes|integer|min:0|max:60',
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
            'tolerancia_entrada_minutos',
            'tolerancia_salida_minutos',
            'dias_semana',
            'activo',
        ]));

        return response()->json($horario);
    }

    /**
     * Elimina un horario de institución.
     *
     * Los supervisores solo pueden eliminar horarios de sus instituciones asignadas.
     */
    public function destroy(Request $request, $id)
    {
        $horario = HorarioInstitucion::findOrFail($id);

        if ($request->user()->esSupervisor()) {
            if (!$request->user()->instituciones->pluck('id')->contains($horario->institucion_id)) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        }

        $this->authorize('delete', $horario);

        $horario->delete();

        return response()->json(['message' => 'Horario eliminado']);
    }

    /**
     * Valida que las horas sean coherentes con el tipo de turno.
     *
     * Actualmente deshabilitada: la lógica está comentada para permitir mayor
     * flexibilidad en la configuración de horarios. Se mantiene el bloque
     * comentado como referencia de la validación original.
     */
    protected function validarCoherenciaTurno(string $nombreTurno, string $horaEntrada, string $horaSalida): void
    {
        // Restricción de horarios deshabilitada temporalmente para mayor flexibilidad
        return;

        /*
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
        */
    }


}