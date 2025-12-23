<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\AsistenciasMultipleExport;
use App\Exports\InstitucionReportExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Asistencia;
use App\Models\AsistenciaDiaria; // ✅ Added
use App\Models\AuditLog;
use App\Models\UsuarioWeb;
use App\Services\AsistenciaService;
use App\Services\DailyStateCalculatorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB; // ✅ Added
use Carbon\Carbon;

class AsistenciaController extends Controller
{
    use AuthorizesRequests;

    protected AsistenciaService $asistenciaService;
    protected \App\Services\AttendanceValidationService $validationService;

    public function __construct(
        AsistenciaService $asistenciaService,
        \App\Services\AttendanceValidationService $validationService
    ) {
        $this->asistenciaService = $asistenciaService;
        $this->validationService = $validationService;
    }

    /**
     * Helper: Verifica si el usuario es super_admin o administrador
     * ✅ CORREGIDO: Usar ROL_ADMINISTRADOR en lugar de ROL_ADMIN
     */
    private function esAdministrador($user): bool
    {
        return $user && in_array($user->rol ?? null, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMINISTRADOR,
        ], true);
    }

    private function getAsignacionPrincipalUsuarioApp(int $usuarioAppId)
    {
        return \App\Models\UsuarioAppInstitucion::query()
            ->where('usuario_app_id', $usuarioAppId)
            ->where('estado', \App\Models\UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->vigentes()
            ->whereNotNull('horario_institucion_id')
            // Prioridad: la que inicia más recientemente, luego la más nueva
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->first();
    }



    /**
     * Listar asistencias con filtros y paginación
     * ✅ CORREGIDO: Supervisor solo ve SUS instituciones
     */
    /**
     * Listar marcaciones (AsistenciaDiaria) con filtros y paginación
     * FASE 6: Listado plano para revisión granular
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            // Query base sobre AsistenciaDiaria (marcaciones individuales)
            $query = AsistenciaDiaria::query()
                ->with([
                    'asistencia.usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
                    'asistencia.institucion:id,nombre,codigo_modular_ie',
                    'asistencia.horario:id,nombre_turno,hora_entrada,hora_salida',
                    'revisadoPor:id,nombre,email'
                ])
                ->orderBy('marcada_en', 'desc');

            // Joins para filtros eficientes (sin cargar todos los modelos)
            $query->join('asistencias', 'asistencias_diarias.asistencia_id', '=', 'asistencias.id');
            $query->select('asistencias_diarias.*'); // Evitar colisiones de ID

            // ✅ FILTRO OBLIGATORIO: Supervisores solo ven SUS instituciones
            if (!$this->esAdministrador($user)) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');

                if ($institucionesIds->isEmpty()) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'current_page' => 1,
                        'total' => 0,
                        'message' => 'No tienes instituciones asignadas'
                    ]);
                }

                $query->whereIn('asistencias.institucion_id', $institucionesIds);
            }

            // ✅ FILTROS ADICIONALES
            if ($request->filled('institucion_id')) {
                // Validación de acceso simple
                if (!$this->esAdministrador($user)) {
                    $institucionesIds = $user->institucionesVigentes()->pluck('id');
                    if (!$institucionesIds->contains($request->institucion_id)) {
                        return response()->json(['message' => 'Sin acceso a institución'], 403);
                    }
                }
                $query->where('asistencias.institucion_id', $request->institucion_id);
            }

            if ($request->filled('fecha_inicio')) {
                $query->whereDate('marcada_en', '>=', $request->fecha_inicio);
            }

            if ($request->filled('fecha_fin')) {
                $query->whereDate('marcada_en', '<=', $request->fecha_fin);
            }

            // Filtro por Estado de Marcación (VALIDA, OBSERVADA...)
            if ($request->filled('estado_marcacion')) {
                $query->where('asistencias_diarias.estado_marcacion', $request->estado_marcacion);
            }

            // Filtro por Estado de Revisión (PENDIENTE, APROBADA...)
            if ($request->filled('estado_revision')) {
                $query->where('asistencias_diarias.estado_revision', $request->estado_revision);
            }

            // Filtro por Tipo (ENTRADA, SALIDA)
            if ($request->filled('tipo')) {
                $query->where('asistencias_diarias.tipo', $request->tipo);
            }

            // Búsqueda por Docente (requiere join o whereHas)
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('asistencia.usuario', function ($q) use ($search) {
                    $q->where('codigo_modular', 'like', "%{$search}%")
                        ->orWhere('nombres', 'like', "%{$search}%")
                        ->orWhere('apellido_paterno', 'like', "%{$search}%")
                        ->orWhere('apellido_materno', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(apellido_paterno, ' ', apellido_materno, ' ', nombres) LIKE ?", ["%{$search}%"]);
                });
            }

            $perPage = $request->input('per_page', 20);
            $marcaciones = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $marcaciones
            ]);

        } catch (\Exception $e) {
            Log::error("Error Index Asistencias: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar cabeceras diarias de asistencia (para vista principal web)
     * FASE 5: Endpoint que retorna asistencias (cabecera) en lugar de marcaciones
     * Aquí aparece FALTA materializado por el job de cierre
     */
    public function listCabeceras(Request $request)
    {
        try {
            $user = $request->user();

            // Query base sobre Asistencia (cabeceras diarias)
            $query = Asistencia::query()
                ->with([
                    'usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
                    'institucion:id,nombre,codigo_modular_ie',
                    'horario:id,nombre_turno,hora_entrada,hora_salida'
                ])
                ->orderBy('fecha', 'desc')
                ->orderBy('created_at', 'desc');

            // ✅ FILTRO OBLIGATORIO: Supervisores solo ven SUS instituciones
            if (!$this->esAdministrador($user)) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');

                if ($institucionesIds->isEmpty()) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'current_page' => 1,
                        'total' => 0,
                        'message' => 'No tienes instituciones asignadas'
                    ]);
                }

                $query->whereIn('institucion_id', $institucionesIds);
            }

            // ✅ FILTROS ADICIONALES
            if ($request->filled('institucion_id')) {
                // Validación de acceso
                if (!$this->esAdministrador($user)) {
                    $institucionesIds = $user->institucionesVigentes()->pluck('id');
                    if (!$institucionesIds->contains($request->institucion_id)) {
                        return response()->json(['message' => 'Sin acceso a institución'], 403);
                    }
                }
                $query->where('institucion_id', $request->institucion_id);
            }

            if ($request->filled('fecha_inicio')) {
                $query->whereDate('fecha', '>=', $request->fecha_inicio);
            }

            if ($request->filled('fecha_fin')) {
                $query->whereDate('fecha', '<=', $request->fecha_fin);
            }

            // Filtro por Estado Diario (FALTA, PRESENTE, TARDANZA, JUSTIFICADO...)
            if ($request->filled('estado_diario')) {
                $query->where('estado_diario', $request->estado_diario);
            }

            // Búsqueda por Docente
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('usuario', function ($q) use ($search) {
                    $q->where('codigo_modular', 'like', "%{$search}%")
                        ->orWhere('nombres', 'like', "%{$search}%")
                        ->orWhere('apellido_paterno', 'like', "%{$search}%")
                        ->orWhere('apellido_materno', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(apellido_paterno, ' ', apellido_materno, ' ', nombres) LIKE ?", ["%{$search}%"]);
                });
            }

            // Añadir conteo de marcaciones observadas pendientes
            $query->withCount([
                'marcaciones as marcaciones_pendientes' => function ($q) {
                    $q->where('estado_marcacion', 'OBSERVADA')
                        ->where('estado_revision', 'PENDIENTE');
                }
            ]);

            $perPage = $request->input('per_page', 20);
            $cabeceras = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $cabeceras
            ]);

        } catch (\Exception $e) {
            Log::error("Error listCabeceras: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar revisión de una marcación individual
     * FASE 6: Permitir aprobar o mantener observada una marcación
     */
    public function updateReview(Request $request, $id)
    {
        try {
            $user = $request->user();

            $request->validate([
                'estado_revision' => 'required|in:PENDIENTE,APROBADA,MANTENER_OBSERVADA',
                'observacion' => 'nullable|string|max:500'
            ]);

            $marcacion = AsistenciaDiaria::with('asistencia')->findOrFail($id);

            // ✅ VALIDAR ACCESO
            if (!$this->esAdministrador($user)) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                if (!$institucionesIds->contains($marcacion->asistencia->institucion_id)) {
                    return response()->json(['message' => 'No tienes acceso a esta marcación'], 403);
                }
            }

            $marcacion->update([
                'estado_revision' => $request->estado_revision,
                'revision_observacion' => $request->observacion,
                'revisado_por_usuario_web_id' => $user->id,
                'revisado_en' => now(),
            ]);

            // ✅ DISPARADOR 2: Recalcular estado diario de la asistencia relacionada
            if ($marcacion->asistencia) {
                $calculator = app(DailyStateCalculatorService::class);
                $resultado = $calculator->calculate($marcacion->asistencia);
                $marcacion->asistencia->update($resultado);
            }

            // Auditoría (comentado temporalmente - faltan constantes)
            /*
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_USUARIO_WEB,
                'actor_id' => $user->id,
                'target_type' => AuditLog::TARGET_ASISTENCIA,
                'target_id' => $marcacion->asistencia_id,
                'action' => AuditLog::ACTION_UPDATE,
                'description' => "Revisión de marcación #{$marcacion->id}: {$request->estado_revision}",
                'metadata' => [
                    'marcacion_id' => $marcacion->id,
                    'estado_anterior' => $marcacion->getOriginal('estado_revision'),
                    'estado_nuevo' => $request->estado_revision,
                    'observacion' => $request->observacion
                ]
            ]);
            */


            return response()->json([
                'success' => true,
                'message' => 'Revisión guardada correctamente',
                'data' => $marcacion->fresh(['asistencia.usuario', 'asistencia.institucion', 'asistencia.horario', 'revisadoPor'])
            ]);

        } catch (\Exception $e) {
            Log::error("Error updateReview: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve the active assignment based on the current time (Smart Shift Selection)
     * OR return the specific assignment requested by ID
     */
    private function resolveAsignacionActiva($user, $institucionId, $requestedHorarioId = null)
    {
        $now = now('America/Lima');

        $asignaciones = \App\Models\UsuarioAppInstitucion::query()
            ->where('usuario_app_id', $user->id)
            ->where('institucion_id', $institucionId)
            ->where('estado', \App\Models\UsuarioAppInstitucion::ESTADO_ACTIVO)
            ->vigentes($now)
            ->whereNotNull('horario_institucion_id')
            ->with('horario'); // Eager load horario

        $asignaciones = $asignaciones->get();

        if ($asignaciones->isEmpty()) {
            return null;
        }

        // 1. Explicit Override
        if ($requestedHorarioId) {
            $found = $asignaciones->firstWhere('horario_institucion_id', $requestedHorarioId);
            if ($found)
                return $found;
        }

        if ($asignaciones->isEmpty()) {
            return null;
        }

        if ($asignaciones->count() === 1) {
            return $asignaciones->first();
        }

        // --- Lógica de Selección Inteligente ---
        // $now ya definido al inicio
        $bestAsignacion = null;
        $minDistanceMinutes = PHP_INT_MAX;

        foreach ($asignaciones as $asignacion) {
            $horario = $asignacion->horario;
            if (!$horario)
                continue;

            // 1. Obtener estado para ver si "puede_marcar" ahora mismo
            $estado = $this->asistenciaService->obtenerEstadoMarcacion(
                $user->id,
                $institucionId,
                $horario->id,
                $now
            );

            // PRIORIDAD 1: Si puede marcar ahora, ¡es este!
            if ($estado['puede_marcar']) {
                return $asignacion;
            }

            // PRIORIDAD 2: El que esté más cerca de iniciar (Entrada) o finalizar (Salida)
            // Calculamos distancia absoluta en minutos a la hora de entrada y salida
            $fechaBase = $now->format('Y-m-d');
            $entrada = Carbon::parse("$fechaBase {$horario->hora_entrada}", 'America/Lima');
            $salida = Carbon::parse("$fechaBase {$horario->hora_salida}", 'America/Lima');

            $distEntrada = abs($now->diffInMinutes($entrada, false));
            $distSalida = abs($now->diffInMinutes($salida, false));

            // La distancia relevante es la menor de las dos (¿estoy más cerca de entrar o salir?)
            $localMin = min($distEntrada, $distSalida);

            if ($localMin < $minDistanceMinutes) {
                $minDistanceMinutes = $localMin;
                $bestAsignacion = $asignacion;
            }
        }

        return $bestAsignacion ?? $asignaciones->first(); // Fallback
    }

    /**
     * Registrar asistencia (desde la app)
     * ✅ CORREGIDO: Usar resolveAsignacionActiva
     */
    public function store(Request $request)
    {
        // ✅ LOG: Verificar si llega la petición desde la app
        \Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        \Log::info('📱 ASISTENCIA - Petición recibida desde la app');
        \Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        \Log::info('👤 Usuario autenticado:', [
            'id' => $request->user()?->id,
            'codigo_modular' => $request->user()?->codigo_modular,
            'nombre' => $request->user()?->nombre_completo,
        ]);

        try {
            $validated = $request->validate([
                'institucion_id' => 'required|integer|exists:instituciones,id',
                'horario_institucion_id' => 'nullable|integer|exists:horarios_institucion,id', // ✅ NUEVO
                'fecha_hora' => 'required|date',
                'latitud' => 'required|numeric',
                'longitud' => 'required|numeric',
                'tipo' => 'required|in:ENTRADA,SALIDA',
                'foto' => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048',
                'offline_uuid' => 'nullable|uuid', // ✅ Idempotencia
            ]);

            $user = $request->user();
            // ✅ Parse info y conversiones de tiempo
            $fecha = Carbon::parse($validated['fecha_hora'], 'America/Lima')->utc();
            $fechaDia = $fecha->setTimezone('America/Lima')->format('Y-m-d'); // Fecha local para el HEADER

            // ✅ RESOLVER ASIGNACIÓN INTELIGENTE (con soporte manual)
            $requestedHorario = $validated['horario_institucion_id'] ?? null;
            $asignacion = $this->resolveAsignacionActiva($user, $validated['institucion_id'], $requestedHorario);

            if (!$asignacion)
                return response()->json(['success' => false, 'message' => 'No estás asignado a esta institución'], 403);

            // Validaciones redundantes pero seguras
            if (!$asignacion->esVigenteEn($fechaDia)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tu asignación no es vigente para esta fecha'
                ], 403);
            }

            // ✅ VALIDAR HORARIO ASIGNADO (OBLIGATORIO)
            if (!$asignacion->horario_institucion_id)
                return response()->json(['success' => false, 'message' => 'Sin horario asignado. Contacta al administrador.'], 403);

            // Validar día laborable
            $validacion = $this->asistenciaService->esDiaLaborableConHorario(
                now('America/Lima'),
                (int) $asignacion->institucion_id,
                (int) $asignacion->horario_institucion_id
            );

            if (!$validacion['laborable']) {
                return response()->json(['success' => false, 'message' => $validacion['motivo']], 403);
            }

            // Validar Idempotencia (Offline UUID) ANTES de la transacción para responder rápido
            if (!empty($validated['offline_uuid'])) {
                $existente = AsistenciaDiaria::where('offline_uuid', $validated['offline_uuid'])->first();
                if ($existente) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Marcación ya registrada (idempotente)',
                        'data' => $existente
                    ]);
                }
            }

            return DB::transaction(function () use ($user, $validated, $asignacion, $fecha, $fechaDia, $validacion, $request) {

                // 1. HEADER: Crear o recuperar asistencia del día
                $asistencia = Asistencia::firstOrCreate(
                    [
                        'usuario_app_id' => $user->id,
                        'institucion_id' => $validated['institucion_id'],
                        'fecha' => $fechaDia,
                    ],
                    [
                        'horario_institucion_id' => $asignacion->horario_institucion_id,
                        // estado_diario defaults to FALTA (from model)
                        'observacion' => null,
                    ]
                );

                // Asegurarse de que el horario en la asistencia coincida con la asignación resuelta (si se creó recién o si se permite update)
                // Nota: Si ya existía con otro horario (e.g. turno mañana) y ahora marca turno tarde, esto podría ser complejo.
                // Por ahora asumimos que la asistencia del día se vincula al PRIMER turno que tocó. 
                // PERO, si marcamos SALIDA, debe coincidir.
                // TODO: Soporte para múltiples asistencias (turnos) en un mismo día. Por ahora, un día = una asistencia header.
                // Si el sistema soporta múltiples turnos/día, la DateUniqueKey(usuario,fecha,institucion) en Asistencia estorba.

                // 2. LOGIC: Cálculos
                $institucion = \App\Models\Institucion::findOrFail($validated['institucion_id']);
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

                // Determinar estado de marcación (detalle)
                $estadoMarcacion = 'VALIDA';
                if (!$dentroRango) {
                    $estadoMarcacion = 'OBSERVADA'; // Regla de negocio
                }

                // Guardar foto
                $fotoPath = $this->asistenciaService->guardarFotoArchivo($request->file('foto'), 'store');

                // Determinar estado de marcación basado en resultado
                $estadoMarcacionFinal = $estadoMarcacion;
                $motivoFinal = !$dentroRango ? 'FUERA_DE_RANGO' : null;

                // Si la salida requiere observación (tardía), marcar como OBSERVADA
                if ($validated['tipo'] === 'SALIDA' && isset($calcResultado['requiere_observacion']) && $calcResultado['requiere_observacion']) {
                    $estadoMarcacionFinal = AsistenciaDiaria::ESTADO_OBSERVADA;
                    $motivoFinal = $calcResultado['motivo_observacion'] ?? 'FUERA_DE_HORARIO';
                }

                // 3. DETAIL: Crear marcación
                $marcacion = AsistenciaDiaria::create([
                    'asistencia_id' => $asistencia->id,
                    'tipo' => $validated['tipo'],
                    'marcada_en' => $validated['fecha_hora'], // Keep original timestamp
                    'latitud' => $validated['latitud'],
                    'longitud' => $validated['longitud'],
                    'distancia_m' => 0, // TODO: Calcular distancia real si es necesario o dejar que el service lo haga
                    'dentro_rango' => $dentroRango,
                    'estado_marcacion' => $estadoMarcacionFinal,
                    'motivo' => $motivoFinal,
                    'foto_url' => $fotoPath,
                    'offline_uuid' => $validated['offline_uuid'] ?? null,
                    'registrado_en' => 'APP_ONLINE',
                    'estado_revision' => $estadoMarcacionFinal === 'OBSERVADA' ? 'PENDIENTE' : 'APROBADA',
                ]);

                // Update estado_diario based on marking
                if ($validated['tipo'] === 'ENTRADA') {
                    // Update header with calculated status
                    $asistencia->estado_diario = $calcResultado['estado_diario'];
                    $asistencia->minutos_tardanza = $calcResultado['minutos_tardanza'];

                    // Update hora_entrada if first or earlier
                    if (!$asistencia->hora_entrada || $fecha->format('H:i:s') < $asistencia->hora_entrada) {
                        $asistencia->hora_entrada = $fecha->format('H:i:s');
                    }
                } elseif ($validated['tipo'] === 'SALIDA') {
                    // Si es la última salida, actualizamos
                    if (!$asistencia->hora_salida || $fecha->format('H:i:s') > $asistencia->hora_salida) {
                        $asistencia->hora_salida = $fecha->format('H:i:s');
                    }
                }

                $asistencia->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Asistencia registrada correctamente',
                    'data' => [
                        'asistencia' => $asistencia,
                        'marcacion' => $marcacion
                    ]
                ], 201);
            });

        } catch (\Exception $e) {
            Log::error("Error en store(): " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error al registrar asistencia",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ... historial, show, getMarcacion, foto unchanged ...

    /**
     * Historial de asistencias de un usuario (para la app)
     */
    public function historial(Request $request, $usuarioId)
    {
        try {
            $user = $request->user();

            // Validar que el usuario solicite su propio historial
            if ((int) $user->id !== (int) $usuarioId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver este historial'
                ], 403);
            }

            // Obtener historial ordenado por fecha descendente
            // Limitamos a los últimos 30 días para no sobrecargar
            $fechaLimite = now()->subDays(30);

            $asistencias = Asistencia::where('usuario_app_id', $user->id)
                ->where('fecha', '>=', $fechaLimite->toDateString())
                ->with(['horario:id,nombre_turno,hora_entrada,hora_salida', 'marcaciones']) // Include details
                ->orderBy('fecha', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $asistencias
            ]);

        } catch (\Exception $e) {
            Log::error("Error en historial(): " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error al obtener historial",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Estado del día (para la app) - MEJORADO con Smart Shift Selection
     */
    public function estadoDia(Request $request)
    {
        /** @var \App\Models\UsuarioApp $user */
        $user = $request->user();
        $today = now('America/Lima');

        // Obtener asignación principal o resolver con override
        $requestedHorario = $request->input('horario_institucion_id');
        $institucionId = $request->input('institucion_id');

        if ($institucionId) {
            $asignacion = $this->resolveAsignacionActiva($user, $institucionId, $requestedHorario);
        } else {
            // Fallback legacy (sin argumentos)
            $asignacion = $this->getAsignacionPrincipalUsuarioApp($user->id);
            $institucionId = $asignacion?->institucion_id;
        }

        if (!$asignacion) {
            return response()->json([
                'server_now' => $today->toIso8601String(),
                'laborable' => false,
                'motivo' => 'Sin asignación vigente con horario',
                'puede_marcar' => false,
                'next_action' => 'NONE',
                'windows' => null,
                'mensaje_estado' => 'Sin asignación vigente con horario',
                'horario' => null,
                'active_assignments' => [], // Empty list
            ]);
        }

        // ✅ OBTENER LISTA COMPLETA DE ASIGNACIONES (para el selector)
        $allAssignments = [];
        if ($institucionId) {
            $allAssignments = \App\Models\UsuarioAppInstitucion::query()
                ->where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->where('estado', \App\Models\UsuarioAppInstitucion::ESTADO_ACTIVO)
                ->vigentes($today)
                ->whereNotNull('horario_institucion_id')
                ->with('horario:id,nombre_turno,hora_entrada,hora_salida')
                ->get()
                ->map(function ($a) use ($today) {
                    $horario = $a->horario;
                    // Agregar flag si es "hoy" (laborable)
                    // TODO: Optimizar esto si hay muchos
                    return [
                        'asignacion_id' => $a->id, // ID del vínculo
                        'horario_id' => $horario->id,
                        'nombre_turno' => $horario->nombre_turno,
                        'hora_entrada' => $horario->hora_entrada,
                        'hora_salida' => $horario->hora_salida,
                        'cargo' => $a->cargo,
                    ];
                });

            \Log::info("🔍 [estadoDia] Asignaciones encontradas para userID {$user->id} instID {$institucionId}: " . count($allAssignments));
        }

        $validacion = $this->asistenciaService->esDiaLaborableConHorario(
            $today,
            (int) $asignacion->institucion_id,
            (int) $asignacion->horario_institucion_id
        );

        if (!$validacion['laborable']) {
            return response()->json([
                'server_now' => $today->toIso8601String(),
                'laborable' => false,
                'motivo' => $validacion['motivo'],
                'puede_marcar' => false,
                'next_action' => 'NONE',
                'windows' => null,
                'mensaje_estado' => $validacion['motivo'],
                'horario' => null,
                'active_assignments' => $allAssignments, // Send List
                'current_horario_id' => $asignacion->horario_institucion_id,
            ]);
        }

        // Obtener estado completo de marcación usando el nuevo método
        $estadoMarcacion = $this->asistenciaService->obtenerEstadoMarcacion(
            $user->id,
            (int) $asignacion->institucion_id,
            (int) $asignacion->horario_institucion_id,
            $today
        );

        // Combinar respuestas
        return response()->json([
            'server_now' => $estadoMarcacion['server_now'],
            'laborable' => true,
            'motivo' => null,
            'puede_marcar' => $estadoMarcacion['puede_marcar'],
            'next_action' => $estadoMarcacion['next_action'],
            'active_assignments' => $allAssignments,
            'current_horario_id' => $asignacion->horario_institucion_id,
            'windows' => $estadoMarcacion['windows'],
            'mensaje_estado' => $estadoMarcacion['mensaje_estado'],
            'institucion_id' => (int) $asignacion->institucion_id,
            'horario_institucion_id' => (int) $asignacion->horario_institucion_id,
            'horario' => $estadoMarcacion['horario'] ?? null,
            // Send List
            'current_assignment_id' => $asignacion->id,
        ]);
    }
    /**
     * Resumen semanal (para la app)
     */
    public function resumenSemanal()
    {
        /** @var \App\Models\UsuarioApp $user */
        $user = Auth::user();
        $hoy = Carbon::now();
        $inicioSemana = $hoy->copy()->startOfWeek();
        $finSemana = $hoy->copy()->endOfWeek();

        $asistencias = Asistencia::where('usuario_app_id', $user->id)
            ->whereBetween('fecha', [$inicioSemana, $finSemana])
            ->orderBy('fecha')
            ->get();

        $dias = collect();
        for ($dia = $inicioSemana->copy(); $dia <= $finSemana; $dia->addDay()) {
            $fecha = $dia->format('Y-m-d');

            $registros = $asistencias->filter(function ($item) use ($fecha) {
                return $item->fecha->format('Y-m-d') === $fecha;
            });

            // Logic adjusted for Header architecture
            // $registro is the daily header now
            $header = $registros->first();

            $dias->push([
                'fecha' => $fecha,
                'dia' => $dia->isoFormat('dddd'),
                'entrada' => $header?->hora_entrada,
                'salida' => $header?->hora_salida,
                'puntual' => $header && $header->estado_diario !== 'TARDANZA', // Simplified logic
                'faltó' => !$header || $header->estado_diario === 'FALTA'
            ]);
        }

        return response()->json([
            'usuario' => $user->id,
            'desde' => $inicioSemana->toDateString(),
            'hasta' => $finSemana->toDateString(),
            'resumen' => $dias,
            'totales' => [
                'dias_asistidos' => $dias->where('faltó', false)->count(),
                'faltas' => $dias->where('faltó', true)->count(),
                'puntual' => $dias->where('puntual', true)->count(),
                'impuntual' => $dias->where('puntual', false)->count(),
            ]
        ]);
    }

    /**
     * Resumen mensual para gráfico (optimizado)
     */
    public function resumenMensualGrafico(Request $request)
    {
        /** @var \App\Models\UsuarioApp $user */
        $user = $request->user();

        $hoy = now('America/Lima');
        $inicioMes = $hoy->copy()->startOfMonth();
        $finMes = $hoy->copy()->endOfMonth();

        $asignacion = $this->getAsignacionPrincipalUsuarioApp($user->id);

        if (!$asignacion) {
            return response()->json([
                'labels' => [],
                'asistencias' => [],
                'faltas' => [],
                'periodo' => [
                    'mes' => $hoy->isoFormat('MMMM YYYY'),
                    'inicio' => $inicioMes->toDateString(),
                    'fin' => $finMes->toDateString(),
                ],
                'motivo' => 'Sin asignación vigente con horario',
            ]);
        }

        $institucionId = (int) $asignacion->institucion_id;
        $horarioId = (int) $asignacion->horario_institucion_id;

        // Traer asistencias del mes para ese usuario + institución + horario
        $asistenciasMes = Asistencia::query()
            ->where('usuario_app_id', $user->id)
            ->where('institucion_id', $institucionId)
            ->where('horario_institucion_id', $horarioId)
            ->whereBetween('fecha', [$inicioMes->toDateString(), $finMes->toDateString()])
            ->get()
            ->groupBy(fn($a) => $a->fecha->format('Y-m-d'));

        $labels = [];
        $asistencias = [];
        $faltas = [];

        $diasMes = min($hoy->day, $finMes->day);

        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $fecha = $inicioMes->copy()->day($dia);

            if ($fecha->isFuture()) {
                continue;
            }

            // Solo días laborables según el horario asignado + feriados
            $validacion = $this->asistenciaService->esDiaLaborableConHorario(
                $fecha,
                $institucionId,
                $horarioId
            );

            if (!$validacion['laborable']) {
                continue;
            }

            $labels[] = (string) $dia;

            $fechaStr = $fecha->format('Y-m-d');
            $registrosDia = $asistenciasMes->get($fechaStr, collect());

            // Considera "asistió" si hay ENTRADA o SALIDA
            $tieneEntrada = $registrosDia->where('tipo', Asistencia::TIPO_ENTRADA)->isNotEmpty();
            $tieneSalida = $registrosDia->where('tipo', Asistencia::TIPO_SALIDA)->isNotEmpty();

            if ($tieneEntrada || $tieneSalida) {
                $asistencias[] = 1;
                $faltas[] = 0;
            } else {
                $asistencias[] = 0;
                $faltas[] = 1;
            }
        }

        return response()->json([
            'labels' => $labels,
            'asistencias' => $asistencias,
            'faltas' => $faltas,
            'periodo' => [
                'mes' => $hoy->isoFormat('MMMM YYYY'),
                'inicio' => $inicioMes->toDateString(),
                'fin' => $finMes->toDateString(),
            ],
            'institucion_id' => $institucionId,
            'horario_institucion_id' => $horarioId,
        ]);
    }

    /**
     * Exportar asistencias (CON AUDITORÍA)
     * ✅ YA CORRECTO: Filtra por instituciones del supervisor
     */
    public function exportar(Request $request)
    {
        $user = $request->user();

        $filters = [
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'institucion_id' => $request->input('institucion_id'),
            'tipo' => $request->input('tipo'),
            'user' => $user,
        ];

        // Contar registros a exportar
        $query = Asistencia::query();

        // ✅ FILTRO OBLIGATORIO: Supervisor solo exporta SUS instituciones
        if (!$this->esAdministrador($user)) {
            $institucionesIds = $user->institucionesVigentes()->pluck('id');

            if ($institucionesIds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes instituciones asignadas'
                ], 403);
            }

            $query->whereIn('institucion_id', $institucionesIds);
        }

        if ($filters['fecha_inicio']) {
            $query->whereDate('fecha', '>=', $filters['fecha_inicio']);
        }
        if ($filters['fecha_fin']) {
            $query->whereDate('fecha', '<=', $filters['fecha_fin']);
        }
        if ($filters['institucion_id']) {
            // ✅ VALIDAR ACCESO si es supervisor
            if (!$this->esAdministrador($user)) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');

                if (!$institucionesIds->contains($filters['institucion_id'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes acceso a esta institución'
                    ], 403);
                }
            }

            $query->where('institucion_id', $filters['institucion_id']);
        }
        if ($filters['tipo']) {
            $query->where('tipo', $filters['tipo']);
        }

        $totalRegistros = $query->count();

        $filename = 'Reporte_Asistencias_' . now()->format('Y-m-d_His') . '.xlsx';

        // AUDITORÍA DE EXPORTACIÓN
        AuditLog::create([
            'actor_id' => $user->id,
            'actor_type' => get_class($user),
            'actor_nombre' => $user->nombre,
            'actor_rol' => $user->rol,
            'accion' => 'exportado',
            'descripcion' => "Exportación de reporte de asistencias",
            'modelo' => Asistencia::class,
            'modelo_id' => null,
            'modelo_nombre' => 'Reporte de asistencias',
            'metadata' => [
                'total_registros' => $totalRegistros,
                'filtros' => [
                    'fecha_inicio' => $filters['fecha_inicio'],
                    'fecha_fin' => $filters['fecha_fin'],
                    'institucion_id' => $filters['institucion_id'],
                    'tipo' => $filters['tipo'],
                ],
                'archivo' => $filename,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'metodo_http' => $request->method(),
        ]);

        return Excel::download(
            new AsistenciasMultipleExport($filters),
            $filename
        );
    }

    public function exportarInstitucion(Request $request, $institucionId)
    {
        try {
            $user = $request->user();

            // Validar Acceso
            if (!$this->esAdministrador($user)) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                if (!$institucionesIds->contains($institucionId)) {
                    return response()->json(['message' => 'No tienes acceso a esta institución'], 403);
                }
            }

            $institucion = \App\Models\Institucion::findOrFail($institucionId);
            $filename = 'Reporte_' . \Illuminate\Support\Str::slug($institucion->nombre) . '_' . now()->format('Ymd_His') . '.xlsx';

            return Excel::download(
                new InstitucionReportExport(
                    $institucionId,
                    $request->input('fecha_inicio'),
                    $request->input('fecha_fin')
                ),
                $filename
            );

        } catch (\Exception $e) {
            Log::error("Error exportarInstitucion: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sincronización desde la app móvil (REFACTORIZADO)
     * ✅ CORREGIDO: Eliminar 'turno', 'falta', 'estado'
     */
    public function syncMovil(Request $request)
    {
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info('🔍 RECIBIENDO SINCRONIZACIÓN MULTIPART');
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Validación básica
        $validated = $request->validate([
            'asistencias' => 'required|array|min:1',
            'asistencias.*.institucion_id' => 'required|integer|exists:instituciones,id',
            'asistencias.*.fecha_hora' => 'required|date',
            'asistencias.*.latitud' => 'required|numeric',
            'asistencias.*.longitud' => 'required|numeric',
            'asistencias.*.tipo' => 'required|in:ENTRADA,SALIDA',
            'asistencias.*.archivo' => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048',
            'asistencias.*.offline_uuid' => 'nullable|uuid',
        ]);

        $registradas = [];
        $omitidas = [];

        foreach ($validated['asistencias'] as $index => $item) {
            try {
                // Idempotencia rápida
                if (!empty($item['offline_uuid'])) {
                    $existente = AsistenciaDiaria::where('offline_uuid', $item['offline_uuid'])->first();
                    if ($existente) {
                        $registradas[] = $existente; // Ya existe, devolver como éxito
                        continue;
                    }
                }

                DB::transaction(function () use ($item, $request, &$registradas, &$omitidas) {
                    $user = $request->user();

                    // Asignación Check (simplificado)
                    $asignacion = \App\Models\UsuarioAppInstitucion::where('usuario_app_id', $user->id)
                        ->where('institucion_id', $item['institucion_id'])
                        ->vigentes()
                        ->first();

                    if (!$asignacion || !$asignacion->horario_institucion_id) {
                        $omitidas[] = array_merge(Arr::except($item, ['archivo']), ['motivo' => 'Sin asignación válida']);
                        return; // Skip transaction commit
                    }

                    $fecha = Carbon::parse($item['fecha_hora']);
                    // Nota: Backend debe asumir que fecha_hora ya viene en zona correcta o UTC desde el cliente offilne.
                    // Si el cliente envía en UTC, usamos UTC. Si envía local, convertimos.
                    // Asumiremos formato ISO8601 estándar.

                    $fechaDia = $fecha->copy()->setTimezone('America/Lima')->format('Y-m-d');

                    // ✅ VALIDAR VIGENCIA PARA LA FECHA DEL EVENTO (fecha_fin EXCLUSIVA)
                    if (!$asignacion->esVigenteEn($fechaDia)) {
                        $omitidas[] = array_merge(Arr::except($item, ['archivo']), ['motivo' => 'Asignación no vigente para esta fecha']);
                        return; // Skip transaction commit
                    }

                    // 1. Header
                    $asistencia = Asistencia::firstOrCreate(
                        [
                            'usuario_app_id' => $user->id,
                            'institucion_id' => $item['institucion_id'],
                            'fecha' => $fechaDia,
                        ],
                        [
                            'horario_institucion_id' => $asignacion->horario_institucion_id,
                            // estado_diario defaults to FALTA (from model)
                        ]
                    );

                    // 2. Logic & Detail
                    // Re-calcular si está en rango o usar lo que dice el cliente?
                    // Para sync offline, a veces confiamos en el cliente si calculó, pero el backend es autoridad.
                    // Recalculamos.
                    $institucion = \App\Models\Institucion::find($item['institucion_id']);
                    $dentroRango = $this->asistenciaService->estaDentroRango($item['latitud'], $item['longitud'], $institucion);

                    // Retrieve schedule
                    $horario = \App\Models\HorarioInstitucion::find($asignacion->horario_institucion_id);

                    // ✅ NUEVA VALIDACION
                    $valResult = $this->validationService->validateMarking(
                        $item['tipo'],
                        $fecha,
                        $horario,
                        $dentroRango
                    );

                    $fotoPath = isset($item['archivo'])
                        ? $this->asistenciaService->guardarFotoArchivo($item['archivo'], 'sync')
                        : null;

                    $marcacion = AsistenciaDiaria::create([
                        'asistencia_id' => $asistencia->id,
                        'tipo' => $item['tipo'],
                        'marcada_en' => $item['fecha_hora'],
                        'latitud' => $item['latitud'],
                        'longitud' => $item['longitud'],
                        'dentro_rango' => $dentroRango,
                        'estado_marcacion' => $valResult['estado'],
                        'motivo' => $valResult['motivo'],
                        'foto_url' => $fotoPath,
                        'offline_uuid' => $item['offline_uuid'] ?? null,
                        'registrado_en' => 'APP_OFFLINE',
                        'synced_at' => now(),
                    ]);

                    // 3. Update Header
                    if ($item['tipo'] === 'ENTRADA') {
                        // Change from FALTA to PRESENTE (simplified for sync)
                        if ($asistencia->estado_diario === 'FALTA') {
                            $asistencia->estado_diario = 'PRESENTE';
                        }
                        // Update hora_entrada if first or earlier
                        if (!$asistencia->hora_entrada || $fecha->format('H:i:s') < $asistencia->hora_entrada) {
                            $asistencia->hora_entrada = $fecha->format('H:i:s');
                        }
                    } elseif ($item['tipo'] === 'SALIDA') {
                        // Update hora_salida if last or later
                        if (!$asistencia->hora_salida || $fecha->format('H:i:s') > $asistencia->hora_salida) {
                            $asistencia->hora_salida = $fecha->format('H:i:s');
                        }
                    }
                    $asistencia->save();

                    // ✅ DISPARADOR 1: Recalcular estado diario después de crear marcación
                    $calculator = app(DailyStateCalculatorService::class);
                    $resultado = $calculator->calculate($asistencia);
                    $asistencia->update($resultado);

                    $registradas[] = $marcacion;
                });

            } catch (\Exception $e) {
                Log::error("Error sync item: " . $e->getMessage());
                $omitidas[] = array_merge(Arr::except($item, ['archivo']), ['motivo' => 'Error interno: ' . $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'registradas' => count($registradas),
            'omitidas' => count($omitidas),
            'detalles_registradas' => $registradas,
            'detalles_omitidas' => $omitidas
        ]);
    }
}
