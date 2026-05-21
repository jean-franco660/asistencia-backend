<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\AsistenciaResource;
use App\Models\Asistencia;
use App\Models\AsistenciaDiaria;
use App\Models\HorarioInstitucion;
use App\Models\Institucion;
use App\Models\UsuarioAppInstitucion;
use App\Services\AsistenciaService;
use App\Services\AttendanceValidationService;
use App\Services\DailyStateCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

/**
 * Controlador de Asistencias para la APP MÓVIL (Docentes)
 *
 * Responsabilidades:
 * - Registrar marcación (store)
 * - Consultar historial del propio docente (historial)
 * - Consultar estado del día actual (estadoDia)
 * - Resumen semanal / mensual gráfico
 * - Sincronización offline (syncMovil)
 */
class AsistenciaController extends Controller
{
    public function __construct(
        protected AsistenciaService $asistenciaService,
        protected AttendanceValidationService $validationService,
    ) {}

    /* ================================================================
     * REGISTRAR MARCACIÓN (ENTRADA/SALIDA)
     * ================================================================ */

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'institucion_id'        => 'required|integer|exists:instituciones,id',
                'horario_institucion_id' => 'nullable|integer|exists:horarios_institucion,id',
                'fecha_hora'            => 'required|date',
                'latitud'               => 'required|numeric',
                'longitud'              => 'required|numeric',
                'tipo'                  => 'required|in:ENTRADA,SALIDA',
                'foto'                  => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048',
                'offline_uuid'          => 'nullable|uuid',
            ]);

            $user     = $request->user();
            $fecha    = Carbon::parse($validated['fecha_hora'], 'America/Lima')->utc();
            $fechaDia = $fecha->setTimezone('America/Lima')->format('Y-m-d');

            // Resolver asignación inteligente
            $asignacion = $this->resolveAsignacionActiva(
                $user,
                $validated['institucion_id'],
                $validated['horario_institucion_id'] ?? null
            );

            if (!$asignacion) {
                return response()->json(['success' => false, 'message' => 'No estás asignado a esta institución'], 403);
            }

            if (!$asignacion->esVigenteEn($fechaDia)) {
                return response()->json(['success' => false, 'message' => 'Tu asignación no es vigente para esta fecha'], 403);
            }

            if (!$asignacion->horario_institucion_id) {
                return response()->json(['success' => false, 'message' => 'Sin horario asignado. Contacta al administrador.'], 403);
            }

            // Validar día laborable
            $validacion = $this->asistenciaService->esDiaLaborableConHorario(
                now('America/Lima'),
                (int) $asignacion->institucion_id,
                (int) $asignacion->horario_institucion_id
            );

            if (!$validacion['laborable']) {
                return response()->json(['success' => false, 'message' => $validacion['motivo']], 403);
            }

            // Idempotencia (offline UUID)
            if (!empty($validated['offline_uuid'])) {
                $existente = AsistenciaDiaria::where('offline_uuid', $validated['offline_uuid'])->first();
                if ($existente) {
                    return response()->json(['success' => true, 'message' => 'Marcación ya registrada (idempotente)', 'data' => $existente]);
                }
            }

            return DB::transaction(function () use ($user, $validated, $asignacion, $fecha, $fechaDia, $validacion, $request) {
                // 1. HEADER: Crear o recuperar asistencia del día
                $asistencia = Asistencia::firstOrCreate(
                    [
                        'usuario_app_id' => $user->id,
                        'institucion_id' => $validated['institucion_id'],
                        'fecha'          => $fechaDia,
                    ],
                    [
                        'horario_institucion_id' => $asignacion->horario_institucion_id,
                        'observacion'            => null,
                    ]
                );

                // 2. CÁLCULOS
                $institucion = Institucion::findOrFail($validated['institucion_id']);
                $dentroRango = $this->asistenciaService->estaDentroRango(
                    $validated['latitud'],
                    $validated['longitud'],
                    $institucion
                );

                $calcResultado = $this->asistenciaService->calcularEstado(
                    $fecha,
                    $validated['tipo'],
                    $validacion['horario']
                );

                // Estado de marcación
                $estadoMarcacion = $dentroRango ? 'VALIDA' : 'OBSERVADA';
                $motivoFinal     = !$dentroRango ? 'FUERA_DE_RANGO' : null;

                if ($validated['tipo'] === 'SALIDA' && ($calcResultado['requiere_observacion'] ?? false)) {
                    $estadoMarcacion = AsistenciaDiaria::ESTADO_OBSERVADA;
                    $motivoFinal     = $calcResultado['motivo_observacion'] ?? 'FUERA_DE_HORARIO';
                }

                // Guardar foto
                $fotoPath = $this->asistenciaService->guardarFotoArchivo($request->file('foto'), 'store');

                // 3. CREAR MARCACIÓN
                $marcacion = AsistenciaDiaria::create([
                    'asistencia_id'    => $asistencia->id,
                    'tipo'             => $validated['tipo'],
                    'marcada_en'       => $validated['fecha_hora'],
                    'latitud'          => $validated['latitud'],
                    'longitud'         => $validated['longitud'],
                    'distancia_m'      => 0,
                    'dentro_rango'     => $dentroRango,
                    'estado_marcacion' => $estadoMarcacion,
                    'motivo'           => $motivoFinal,
                    'foto_url'         => $fotoPath,
                    'offline_uuid'     => $validated['offline_uuid'] ?? null,
                    'registrado_en'    => 'APP_ONLINE',
                    'estado_revision'  => $estadoMarcacion === 'OBSERVADA' ? 'PENDIENTE' : 'APROBADA',
                ]);

                // 4. ACTUALIZAR HEADER
                $this->actualizarHeaderAsistencia($asistencia, $validated['tipo'], $fecha, $calcResultado);

                return response()->json([
                    'success' => true,
                    'message' => 'Asistencia registrada correctamente',
                    'data'    => ['asistencia' => $asistencia, 'marcacion' => $marcacion],
                ], 201);
            });

        } catch (\Exception $e) {
            Log::error("Error en store(): " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al registrar asistencia', 'error' => $e->getMessage()], 500);
        }
    }

    /* ================================================================
     * HISTORIAL DEL DOCENTE (últimos 30 días)
     * ================================================================ */

    public function historial(Request $request, $usuarioId): JsonResponse
    {
        try {
            $user = $request->user();

            if ((int) $user->id !== (int) $usuarioId) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para ver este historial'], 403);
            }

            $asistencias = Asistencia::where('usuario_app_id', $user->id)
                ->where('fecha', '>=', now()->subDays(30)->toDateString())
                ->with(['horario:id,nombre_turno,hora_entrada,hora_salida', 'marcaciones'])
                ->orderByDesc('fecha')
                ->get();

            return response()->json(['success' => true, 'data' => AsistenciaResource::collection($asistencias)]);

        } catch (\Exception $e) {
            Log::error("Error en historial(): " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al obtener historial'], 500);
        }
    }

    /* ================================================================
     * ESTADO DEL DÍA (Smart Shift Selection)
     * ================================================================ */

    public function estadoDia(Request $request): JsonResponse
    {
        /** @var \App\Models\UsuarioApp $user */
        $user  = $request->user();
        $today = now('America/Lima');

        $requestedHorario = $request->input('horario_institucion_id');
        $institucionId    = $request->input('institucion_id');

        if ($institucionId) {
            $asignacion = $this->resolveAsignacionActiva($user, $institucionId, $requestedHorario);
        } else {
            $asignacion    = $this->getAsignacionPrincipal($user->id);
            $institucionId = $asignacion?->institucion_id;
        }

        $emptyResponse = [
            'server_now'     => $today->toIso8601String(),
            'laborable'      => false,
            'puede_marcar'   => false,
            'next_action'    => 'NONE',
            'windows'        => null,
            'mensaje_estado' => 'Sin asignación vigente con horario',
            'horario'        => null,
        ];

        if (!$asignacion) {
            return response()->json(array_merge($emptyResponse, [
                'motivo'              => 'Sin asignación vigente con horario',
                'active_assignments'  => [],
            ]));
        }

        // Lista de asignaciones activas para el selector de turnos
        $allAssignments = $this->getActiveAssignments($user->id, $institucionId, $today);

        $validacion = $this->asistenciaService->esDiaLaborableConHorario(
            $today,
            (int) $asignacion->institucion_id,
            (int) $asignacion->horario_institucion_id
        );

        if (!$validacion['laborable']) {
            return response()->json(array_merge($emptyResponse, [
                'motivo'               => $validacion['motivo'],
                'mensaje_estado'       => $validacion['motivo'],
                'active_assignments'   => $allAssignments,
                'current_horario_id'   => $asignacion->horario_institucion_id,
            ]));
        }

        $estadoMarcacion = $this->asistenciaService->obtenerEstadoMarcacion(
            $user->id,
            (int) $asignacion->institucion_id,
            (int) $asignacion->horario_institucion_id,
            $today
        );

        return response()->json([
            'server_now'              => $estadoMarcacion['server_now'],
            'laborable'               => true,
            'motivo'                  => null,
            'puede_marcar'            => $estadoMarcacion['puede_marcar'],
            'next_action'             => $estadoMarcacion['next_action'],
            'active_assignments'      => $allAssignments,
            'current_horario_id'      => $asignacion->horario_institucion_id,
            'windows'                 => $estadoMarcacion['windows'],
            'mensaje_estado'          => $estadoMarcacion['mensaje_estado'],
            'institucion_id'          => (int) $asignacion->institucion_id,
            'horario_institucion_id'  => (int) $asignacion->horario_institucion_id,
            'horario'                 => $estadoMarcacion['horario'] ?? null,
            'current_assignment_id'   => $asignacion->id,
        ]);
    }

    /* ================================================================
     * RESUMEN SEMANAL
     * ================================================================ */

    public function resumenSemanal(): JsonResponse
    {
        /** @var \App\Models\UsuarioApp $user */
        $user          = Auth::user();
        $hoy           = Carbon::now();
        $inicioSemana  = $hoy->copy()->startOfWeek();
        $finSemana     = $hoy->copy()->endOfWeek();

        $asistencias = Asistencia::where('usuario_app_id', $user->id)
            ->whereBetween('fecha', [$inicioSemana, $finSemana])
            ->orderBy('fecha')
            ->get()
            ->keyBy(fn ($a) => $a->fecha->format('Y-m-d'));

        $dias = collect();
        for ($dia = $inicioSemana->copy(); $dia <= $finSemana; $dia->addDay()) {
            $fecha  = $dia->format('Y-m-d');
            $header = $asistencias->get($fecha);

            $dias->push([
                'fecha'   => $fecha,
                'dia'     => $dia->isoFormat('dddd'),
                'entrada' => $header?->hora_entrada,
                'salida'  => $header?->hora_salida,
                'puntual' => $header && $header->estado_diario !== 'TARDANZA',
                'faltó'   => !$header || $header->estado_diario === 'FALTA',
            ]);
        }

        return response()->json([
            'usuario' => $user->id,
            'desde'   => $inicioSemana->toDateString(),
            'hasta'   => $finSemana->toDateString(),
            'resumen' => $dias,
            'totales' => [
                'dias_asistidos' => $dias->where('faltó', false)->count(),
                'faltas'         => $dias->where('faltó', true)->count(),
                'puntual'        => $dias->where('puntual', true)->count(),
                'impuntual'      => $dias->where('puntual', false)->count(),
            ],
        ]);
    }

    /* ================================================================
     * RESUMEN MENSUAL (para gráfico de la app)
     * ================================================================ */

    public function resumenMensualGrafico(Request $request): JsonResponse
    {
        /** @var \App\Models\UsuarioApp $user */
        $user      = $request->user();
        $hoy       = now('America/Lima');
        $inicioMes = $hoy->copy()->startOfMonth();
        $finMes    = $hoy->copy()->endOfMonth();

        $asignacion = $this->getAsignacionPrincipal($user->id);

        if (!$asignacion) {
            return response()->json([
                'labels'       => [],
                'asistencias'  => [],
                'faltas'       => [],
                'periodo'      => ['mes' => $hoy->isoFormat('MMMM YYYY'), 'inicio' => $inicioMes->toDateString(), 'fin' => $finMes->toDateString()],
                'motivo'       => 'Sin asignación vigente con horario',
            ]);
        }

        $institucionId = (int) $asignacion->institucion_id;
        $horarioId     = (int) $asignacion->horario_institucion_id;

        $asistenciasMes = Asistencia::where('usuario_app_id', $user->id)
            ->where('institucion_id', $institucionId)
            ->where('horario_institucion_id', $horarioId)
            ->whereBetween('fecha', [$inicioMes->toDateString(), $finMes->toDateString()])
            ->get()
            ->groupBy(fn ($a) => $a->fecha->format('Y-m-d'));

        $labels      = [];
        $asistencias = [];
        $faltas      = [];

        for ($dia = 1; $dia <= min($hoy->day, $finMes->day); $dia++) {
            $fecha = $inicioMes->copy()->day($dia);
            if ($fecha->isFuture()) continue;

            $validacion = $this->asistenciaService->esDiaLaborableConHorario($fecha, $institucionId, $horarioId);
            if (!$validacion['laborable']) continue;

            $labels[]  = (string) $dia;
            $registros = $asistenciasMes->get($fecha->format('Y-m-d'), collect());
            $asistio   = $registros->whereIn('tipo', [Asistencia::TIPO_ENTRADA, Asistencia::TIPO_SALIDA])->isNotEmpty()
                         || $registros->where('estado_diario', '!=', 'FALTA')->isNotEmpty();

            $asistencias[] = $asistio ? 1 : 0;
            $faltas[]      = $asistio ? 0 : 1;
        }

        return response()->json([
            'labels'                 => $labels,
            'asistencias'            => $asistencias,
            'faltas'                 => $faltas,
            'periodo'                => ['mes' => $hoy->isoFormat('MMMM YYYY'), 'inicio' => $inicioMes->toDateString(), 'fin' => $finMes->toDateString()],
            'institucion_id'         => $institucionId,
            'horario_institucion_id' => $horarioId,
        ]);
    }

    /* ================================================================
     * SINCRONIZACIÓN OFFLINE (batch)
     * ================================================================ */

    public function syncMovil(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asistencias'                    => 'required|array|min:1',
            'asistencias.*.institucion_id'   => 'required|integer|exists:instituciones,id',
            'asistencias.*.fecha_hora'       => 'required|date',
            'asistencias.*.latitud'          => 'required|numeric',
            'asistencias.*.longitud'         => 'required|numeric',
            'asistencias.*.tipo'             => 'required|in:ENTRADA,SALIDA',
            'asistencias.*.archivo'          => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048',
            'asistencias.*.offline_uuid'     => 'nullable|uuid',
        ]);

        $registradas = [];
        $omitidas    = [];

        foreach ($validated['asistencias'] as $item) {
            try {
                // Idempotencia
                if (!empty($item['offline_uuid'])) {
                    $existente = AsistenciaDiaria::where('offline_uuid', $item['offline_uuid'])->first();
                    if ($existente) {
                        $registradas[] = $existente;
                        continue;
                    }
                }

                DB::transaction(function () use ($item, $request, &$registradas, &$omitidas) {
                    $user = $request->user();

                    $asignacion = UsuarioAppInstitucion::where('usuario_app_id', $user->id)
                        ->where('institucion_id', $item['institucion_id'])
                        ->vigentes()
                        ->first();

                    if (!$asignacion || !$asignacion->horario_institucion_id) {
                        $omitidas[] = array_merge(Arr::except($item, ['archivo']), ['motivo' => 'Sin asignación válida']);
                        return;
                    }

                    $fecha    = Carbon::parse($item['fecha_hora']);
                    $fechaDia = $fecha->copy()->setTimezone('America/Lima')->format('Y-m-d');

                    if (!$asignacion->esVigenteEn($fechaDia)) {
                        $omitidas[] = array_merge(Arr::except($item, ['archivo']), ['motivo' => 'Asignación no vigente para esta fecha']);
                        return;
                    }

                    // Header
                    $asistencia = Asistencia::firstOrCreate(
                        ['usuario_app_id' => $user->id, 'institucion_id' => $item['institucion_id'], 'fecha' => $fechaDia],
                        ['horario_institucion_id' => $asignacion->horario_institucion_id]
                    );

                    // Cálculos
                    $institucion = Institucion::find($item['institucion_id']);
                    $dentroRango = $this->asistenciaService->estaDentroRango($item['latitud'], $item['longitud'], $institucion);
                    $horario     = HorarioInstitucion::find($asignacion->horario_institucion_id);

                    $valResult = $this->validationService->validateMarking(
                        $item['tipo'], $fecha, $horario, $dentroRango
                    );

                    $fotoPath = isset($item['archivo'])
                        ? $this->asistenciaService->guardarFotoArchivo($item['archivo'], 'sync')
                        : null;

                    $marcacion = AsistenciaDiaria::create([
                        'asistencia_id'    => $asistencia->id,
                        'tipo'             => $item['tipo'],
                        'marcada_en'       => $item['fecha_hora'],
                        'latitud'          => $item['latitud'],
                        'longitud'         => $item['longitud'],
                        'dentro_rango'     => $dentroRango,
                        'estado_marcacion' => $valResult['estado'],
                        'motivo'           => $valResult['motivo'],
                        'foto_url'         => $fotoPath,
                        'offline_uuid'     => $item['offline_uuid'] ?? null,
                        'registrado_en'    => 'APP_OFFLINE',
                        'synced_at'        => now(),
                    ]);

                    // Actualizar header
                    $this->actualizarHeaderAsistencia($asistencia, $item['tipo'], $fecha, null);

                    // Recalcular estado diario
                    $calculator = app(DailyStateCalculatorService::class);
                    $asistencia->update($calculator->calculate($asistencia));

                    $registradas[] = $marcacion;
                });

            } catch (\Exception $e) {
                Log::error("Error sync item: " . $e->getMessage());
                $omitidas[] = array_merge(Arr::except($item, ['archivo']), ['motivo' => 'Error interno: ' . $e->getMessage()]);
            }
        }

        return response()->json([
            'success'              => true,
            'registradas'          => count($registradas),
            'omitidas'             => count($omitidas),
            'detalles_registradas' => $registradas,
            'detalles_omitidas'    => $omitidas,
        ]);
    }

    /* ================================================================
     * MÉTODOS PRIVADOS
     * ================================================================ */

    /**
     * Resolver la asignación activa basada en hora actual (Smart Shift Selection)
     */
    private function resolveAsignacionActiva($user, int $institucionId, ?int $requestedHorarioId = null): ?UsuarioAppInstitucion
    {
        $now = now('America/Lima');

        $asignaciones = UsuarioAppInstitucion::query()
            ->where('usuario_app_id', $user->id)
            ->where('institucion_id', $institucionId)
            ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->vigentes($now)
            ->whereNotNull('horario_institucion_id')
            ->with('horario')
            ->get();

        if ($asignaciones->isEmpty()) return null;

        // Override explícito
        if ($requestedHorarioId) {
            $found = $asignaciones->firstWhere('horario_institucion_id', $requestedHorarioId);
            if ($found) return $found;
        }

        if ($asignaciones->count() === 1) return $asignaciones->first();

        // Selección inteligente: la más cercana al horario actual
        $bestAsignacion      = null;
        $minDistanceMinutes  = PHP_INT_MAX;

        foreach ($asignaciones as $asignacion) {
            $horario = $asignacion->horario;
            if (!$horario) continue;

            $estado = $this->asistenciaService->obtenerEstadoMarcacion(
                $user->id, $institucionId, $horario->id, $now
            );

            if ($estado['puede_marcar']) return $asignacion;

            $fechaBase   = $now->format('Y-m-d');
            $entrada     = Carbon::parse("$fechaBase {$horario->hora_entrada}", 'America/Lima');
            $salida      = Carbon::parse("$fechaBase {$horario->hora_salida}", 'America/Lima');
            $localMin    = min(abs($now->diffInMinutes($entrada, false)), abs($now->diffInMinutes($salida, false)));

            if ($localMin < $minDistanceMinutes) {
                $minDistanceMinutes = $localMin;
                $bestAsignacion     = $asignacion;
            }
        }

        return $bestAsignacion ?? $asignaciones->first();
    }

    /**
     * Obtener asignación principal del usuario
     */
    private function getAsignacionPrincipal(int $usuarioAppId): ?UsuarioAppInstitucion
    {
        return UsuarioAppInstitucion::query()
            ->where('usuario_app_id', $usuarioAppId)
            ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->vigentes()
            ->whereNotNull('horario_institucion_id')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Lista de asignaciones activas para el selector de turnos
     */
    private function getActiveAssignments(int $userId, int $institucionId, Carbon $today): array
    {
        return UsuarioAppInstitucion::query()
            ->where('usuario_app_id', $userId)
            ->where('institucion_id', $institucionId)
            ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->vigentes($today)
            ->whereNotNull('horario_institucion_id')
            ->with('horario:id,nombre_turno,hora_entrada,hora_salida')
            ->get()
            ->map(fn ($a) => [
                'asignacion_id' => $a->id,
                'horario_id'    => $a->horario->id,
                'nombre_turno'  => $a->horario->nombre_turno,
                'hora_entrada'  => $a->horario->hora_entrada,
                'hora_salida'   => $a->horario->hora_salida,
                'cargo'         => $a->cargo,
            ])
            ->toArray();
    }

    /**
     * Actualizar cabecera de asistencia con datos de la marcación
     */
    private function actualizarHeaderAsistencia(Asistencia $asistencia, string $tipo, Carbon $fecha, ?array $calcResultado): void
    {
        if ($tipo === 'ENTRADA') {
            if ($calcResultado) {
                $asistencia->estado_diario   = $calcResultado['estado_diario'];
                $asistencia->minutos_tardanza = $calcResultado['minutos_tardanza'];
            }
            $hora = $fecha->format('H:i:s');
            if (!$asistencia->hora_entrada || $hora < $asistencia->hora_entrada) {
                $asistencia->hora_entrada = $hora;
            }
        } elseif ($tipo === 'SALIDA') {
            $hora = $fecha->format('H:i:s');
            if (!$asistencia->hora_salida || $hora > $asistencia->hora_salida) {
                $asistencia->hora_salida = $hora;
            }
        }

        $asistencia->save();
    }
}
