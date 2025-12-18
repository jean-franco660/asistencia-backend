<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\AsistenciasMultipleExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Asistencia;
use App\Models\AuditLog;
use App\Models\UsuarioWeb;
use App\Services\AsistenciaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AsistenciaController extends Controller
{
    use AuthorizesRequests;

    protected AsistenciaService $asistenciaService;

    public function __construct(AsistenciaService $asistenciaService)
    {
        $this->asistenciaService = $asistenciaService;
    }

    /**
     * Helper: Verifica si el usuario es super_admin o administrador
     * ✅ CORREGIDO: Usar ROL_ADMINISTRADOR en lugar de ROL_ADMIN
     */
    private function esAdministrador(UsuarioWeb $user): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMINISTRADOR,
        ]);
    }

    /**
     * Listar asistencias con filtros y paginación
     * ✅ CORREGIDO: Supervisor solo ve SUS instituciones
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            $query = Asistencia::with([
                'usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
                'institucion:id,nombre,codigo_modular_ie',
                'horario:id,nombre_turno,hora_entrada,hora_salida'
            ])
            ->orderBy('fecha_hora', 'desc');

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

            // ✅ FILTROS ADICIONALES CON VALIDACIÓN DE ACCESO
            if ($request->filled('institucion_id')) {
                // Validar que el supervisor tenga acceso a esta institución
                if (!$this->esAdministrador($user)) {
                    $institucionesIds = $user->institucionesVigentes()->pluck('id');
                    
                    if (!$institucionesIds->contains($request->institucion_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No tienes acceso a esta institución'
                        ], 403);
                    }
                }
                
                $query->where('institucion_id', $request->institucion_id);
            }

            if ($request->filled('fecha_inicio')) {
                $query->whereDate('fecha_hora', '>=', $request->fecha_inicio);
            }

            if ($request->filled('fecha_fin')) {
                $query->whereDate('fecha_hora', '<=', $request->fecha_fin);
            }

            if ($request->filled('tipo')) {
                $query->where('tipo', $request->tipo);
            }

            if ($request->filled('resultado')) {
                $query->where('resultado', $request->resultado);
            }
            
            if ($request->filled('situacion')) {
                $query->where('situacion', $request->situacion);
            }

            // ✅ BÚSQUEDA POR NOMBRE O DNI DEL DOCENTE
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('usuario', function($q) use ($search) {
                    $q->where('codigo_modular', 'like', "%{$search}%")
                    ->orWhere('nombres', 'like', "%{$search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$search}%")
                    ->orWhere('apellido_materno', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(apellido_paterno, ' ', apellido_materno, ' ', nombres) LIKE ?", ["%{$search}%"]);
                });
            }

            $perPage = $request->input('per_page', 20);
            $asistencias = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $asistencias
            ]);

        } catch (\Exception $e) {
            Log::error("Error en AsistenciaController@index: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'error' => "Error al obtener asistencias",
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Registrar asistencia (desde la app)
     * ✅ CORREGIDO: Eliminar campo 'turno', cambiar 'estado' por 'resultado'
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'institucion_id' => 'required|integer|exists:instituciones,id',
                'fecha_hora' => 'required|date',
                'latitud' => 'required|numeric',
                'longitud' => 'required|numeric',
                'tipo' => 'required|in:ENTRADA,SALIDA',  // ✅ MAYÚSCULAS según constantes
                'archivo' => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048',
            ]);

            $user = $request->user();
            $fecha = Carbon::parse($validated['fecha_hora']);

            // ✅ VALIDAR: Usuario debe tener asignación ACTIVA con horario
            $asignacion = \App\Models\UsuarioAppInstitucion::where('usuario_app_id', $user->id)
                ->where('institucion_id', $validated['institucion_id'])
                ->first();

            if (!$asignacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'No estás asignado a esta institución'
                ], 403);
            }

            if ($asignacion->estado === \App\Models\UsuarioAppInstitucion::ESTADO_PENDIENTE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tu asignación está pendiente. Contacta al administrador para que te asigne un horario.'
                ], 403);
            }

            if ($asignacion->estado === \App\Models\UsuarioAppInstitucion::ESTADO_INACTIVO) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tu asignación está inactiva'
                ], 403);
            }

            if (!$asignacion->horario_institucion_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes un horario asignado. Contacta al administrador.'
                ], 403);
            }

            // Validar día laborable
            $validacion = $this->asistenciaService->esDiaLaborable($fecha, $validated['institucion_id']);

            if (!$validacion['laborable']) {
                return response()->json([
                    'success' => false,
                    'message' => $validacion['motivo']
                ], 403);
            }

            // Calcular dentro_rango (backend authoritative)
            $institucion = \App\Models\Institucion::findOrFail($validated['institucion_id']);
            $dentroRango = $this->asistenciaService->estaDentroRango(
                $validated['latitud'],
                $validated['longitud'],
                $institucion
            );

            // Calcular resultado (A_TIEMPO, TARDE, SALIDA_ANTES)
            $resultado = $this->asistenciaService->calcularEstado(
                $fecha,
                $validated['tipo'],
                $validacion['horario']
            );

            // Guardar foto
            $fotoPath = $this->asistenciaService->guardarFotoArchivo($request->file('archivo'), 'store');

            // ✅ CORREGIDO: Registrar asistencia sin campos inexistentes
            $asistencia = Asistencia::create([
                'usuario_app_id' => $user->id,
                'institucion_id' => $validated['institucion_id'],
                'horario_institucion_id' => $validacion['horario']->id,  // ✅ Relación completa
                'fecha' => $fecha->toDateString(),
                'fecha_hora' => $validated['fecha_hora'],
                'tipo' => $validated['tipo'],
                'resultado' => $resultado,  // ✅ CORRECTO: 'resultado' no 'estado'
                'situacion' => Asistencia::SITUACION_NORMAL,
                'latitud' => $validated['latitud'],
                'longitud' => $validated['longitud'],
                'dentro_rango' => $dentroRango,
                'foto' => $fotoPath,
                'sincronizado' => true,
                // ✅ ELIMINADO: 'turno' (no existe en migración, se obtiene por relación)
                // ✅ ELIMINADO: 'estado' (el campo correcto es 'resultado')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Asistencia registrada correctamente',
                'data' => $asistencia->load('horario')  // Cargar relación para obtener turno
            ], 201);

        } catch (\Exception $e) {
            Log::error("Error en store(): " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error al registrar asistencia",
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ver detalle de asistencia
     * ✅ AGREGADO: Validar acceso del supervisor
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $asistencia = Asistencia::with(['usuario', 'institucion', 'horario'])->findOrFail($id);
            
            // ✅ VALIDAR ACCESO: Supervisor solo ve asistencias de SUS instituciones
            if (!$this->esAdministrador($user)) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                
                if (!$institucionesIds->contains($asistencia->institucion_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes acceso a esta asistencia'
                    ], 403);
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $asistencia
            ]);
        } catch (\Exception $e) {
            Log::error("Error en show(): " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener foto de asistencia
     */
    public function foto($id)
    {
        try {
            $asistencia = Asistencia::findOrFail($id);

            if (!$asistencia->foto) {
                abort(404, 'Sin foto');
            }

            $useS3 = env('USE_S3', false);

            if ($useS3) {
                // Generar URL temporal firmada (válida 5 minutos)
                /** @var \Illuminate\Filesystem\AwsS3V3Adapter $disk */
                $disk = Storage::disk('s3');
                $url = $disk->temporaryUrl($asistencia->foto, now()->addMinutes(5));
                return response()->json(['url' => $url]);
            } else {
                // Devolver archivo desde disco local
                $path = storage_path("app/public/{$asistencia->foto}");

                if (!file_exists($path)) {
                    abort(404, 'Foto no encontrada');
                }

                return response()->file($path);
            }

        } catch (\Exception $e) {
            Log::error("Error en foto(): " . $e->getMessage());
            return response()->json(['error' => 'Error al obtener foto'], 500);
        }
    }

    /**
     * Estado del día (para la app)
     */
    public function estadoDia(Request $request)
    {
        $user = $request->user();
        $today = now();

        $instituciones = $user->institucionesActivas->pluck('id');  // ✅ USAR institucionesActivas

        if ($instituciones->isEmpty()) {
            return response()->json([
                'laborable' => false,
                'motivo' => 'Sin instituciones asignadas',
                'puede_marcar' => false,
                'horario' => null,
            ]);
        }

        // Tomar la primera institución del usuario
        $institucionId = $instituciones->first();

        $validacion = $this->asistenciaService->esDiaLaborable($today, $institucionId);

        if (!$validacion['laborable']) {
            return response()->json([
                'laborable' => false,
                'motivo' => $validacion['motivo'],
                'puede_marcar' => false,
                'horario' => null,
            ]);
        }

        return response()->json([
            'laborable' => true,
            'motivo' => null,
            'puede_marcar' => true,
            'horario' => [
                'turno' => $validacion['horario']->nombre_turno,
                'hora_entrada' => $validacion['horario']->hora_entrada,
                'hora_salida' => $validacion['horario']->hora_salida,
                'tolerancia_minutos' => $validacion['horario']->tolerancia_minutos,
            ]
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
            ->whereBetween('fecha_hora', [$inicioSemana, $finSemana])
            ->orderBy('fecha_hora')
            ->get();

        $dias = collect();
        for ($dia = $inicioSemana->copy(); $dia <= $finSemana; $dia->addDay()) {
            $fecha = $dia->format('Y-m-d');

            $registros = $asistencias->filter(function ($item) use ($fecha) {
                return $item->fecha_hora->format('Y-m-d') === $fecha;
            });

            $entrada = $registros->firstWhere('tipo', Asistencia::TIPO_ENTRADA);
            $salida = $registros->firstWhere('tipo', Asistencia::TIPO_SALIDA);

            $dias->push([
                'fecha' => $fecha,
                'dia' => $dia->isoFormat('dddd'),
                'entrada' => $entrada ? $entrada->fecha_hora->format('H:i:s') : null,
                'salida' => $salida ? $salida->fecha_hora->format('H:i:s') : null,
                'puntual' => $entrada ? $entrada->dentro_rango : null,
                'faltó' => $registros->isEmpty()
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
    public function resumenMensualGrafico()
    {
        /** @var \App\Models\UsuarioWeb|null $user */
        $user = Auth::user();
        $hoy = Carbon::now('America/Lima');
        $inicioMes = $hoy->copy()->startOfMonth();
        $finMes = $hoy->copy()->endOfMonth();

        // Super admin y administrador ven todos los datos
        $isAdmin = $this->esAdministrador($user);

        // UNA SOLA QUERY para todo el mes
        $query = Asistencia::query();

        if (!$isAdmin) {
            $query->where('usuario_app_id', $user->id);
        }

        $asistenciasMes = $query->whereBetween('fecha_hora', [$inicioMes, $finMes])
            ->get()
            ->groupBy(function ($item) {
                return $item->fecha_hora->format('Y-m-d');
            });

        $labels = [];
        $asistencias = [];
        $faltas = [];

        $diasMes = min($hoy->day, $finMes->day);

        for ($dia = 1; $dia <= $diasMes; $dia++) {
            $fecha = $inicioMes->copy()->day($dia);

            if ($fecha->isFuture()) {
                continue;
            }

            $labels[] = (string) $dia;

            // Verificar si es laborable usando el service
            $institucionId = $user->institucionesVigentes()->first()->id ?? 1;
            $validacion = $this->asistenciaService->esDiaLaborable($fecha, $institucionId);

            if (!$validacion['laborable']) {
                continue;
            }

            $fechaStr = $fecha->format('Y-m-d');
            $registrosDia = $asistenciasMes->get($fechaStr, collect());

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
            ]
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
            $query->whereDate('fecha_hora', '>=', $filters['fecha_inicio']);
        }
        if ($filters['fecha_fin']) {
            $query->whereDate('fecha_hora', '<=', $filters['fecha_fin']);
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
            'asistencias.*.tipo' => 'required|in:ENTRADA,SALIDA',  // ✅ MAYÚSCULAS
            'asistencias.*.archivo' => 'nullable|file|image|mimes:jpg,jpeg,png|max:2048',
            'asistencias.*.es_falta' => 'required|boolean',  // ✅ RENOMBRADO de 'falta'
        ]);

        $registradas = [];
        $omitidas = [];

        // Cache instituciones
        $institucionesCache = [];

        foreach ($validated['asistencias'] as $index => $item) {
            Log::info("🔍 Procesando asistencia multipart #{$index}");

            // ✅ VALIDAR: Usuario debe tener asignación ACTIVA con horario
            $asignacion = \App\Models\UsuarioAppInstitucion::where('usuario_app_id', $request->user()->id)
                ->where('institucion_id', $item['institucion_id'])
                ->first();

            if (!$asignacion) {
                Log::warning("⚠️ Omitida: Usuario no asignado a institución");
                $omitidas[] = array_merge($item, ['motivo' => 'No estás asignado a esta institución']);
                continue;
            }

            if ($asignacion->estado === \App\Models\UsuarioAppInstitucion::ESTADO_PENDIENTE) {
                Log::warning("⚠️ Omitida: Asignación pendiente");
                $omitidas[] = array_merge($item, ['motivo' => 'Tu asignación está pendiente. Necesitas un horario asignado.']);
                continue;
            }

            if ($asignacion->estado === \App\Models\UsuarioAppInstitucion::ESTADO_INACTIVO) {
                Log::warning("⚠️ Omitida: Asignación inactiva");
                $omitidas[] = array_merge($item, ['motivo' => 'Tu asignación está inactiva']);
                continue;
            }

            if (!$asignacion->horario_institucion_id) {
                Log::warning("⚠️ Omitida: Sin horario asignado");
                $omitidas[] = array_merge($item, ['motivo' => 'No tienes un horario asignado']);
                continue;
            }

            $fecha = Carbon::parse($item['fecha_hora']);

            // Validar día laborable
            $validacion = $this->asistenciaService->esDiaLaborable($fecha, $item['institucion_id']);

            if (!$validacion['laborable']) {
                Log::warning("⚠️ Omitida por día no laborable: {$validacion['motivo']}");
                $omitidas[] = array_merge($item, ['motivo' => $validacion['motivo']]);
                continue;
            }

            // Cálculos backend
            $resultado = $this->asistenciaService->calcularEstado(
                $fecha,
                $item['tipo'],
                $validacion['horario']
            );

            // Institución cache
            if (!isset($institucionesCache[$item['institucion_id']])) {
                $institucionesCache[$item['institucion_id']] = \App\Models\Institucion::find($item['institucion_id']);
            }
            $institucion = $institucionesCache[$item['institucion_id']];

            $dentroRango = false;
            if ($institucion) {
                $dentroRango = $this->asistenciaService->estaDentroRango(
                    $item['latitud'],
                    $item['longitud'],
                    $institucion
                );
            }

            // Guardar foto
            $fileInputName = "asistencias.{$index}.archivo";
            $fotoPath = null;
            if ($request->hasFile($fileInputName)) {
                $fotoPath = $this->asistenciaService->guardarFotoArchivo($request->file($fileInputName), 'syncMovil');
            }

            // ✅ CORREGIDO: Registrar asistencia sin campos inexistentes
            $registro = Asistencia::create([
                'usuario_app_id' => $request->user()->id,
                'institucion_id' => $item['institucion_id'],
                'horario_institucion_id' => $validacion['horario']->id,
                'fecha' => $fecha->toDateString(),
                'fecha_hora' => $item['fecha_hora'],
                'tipo' => $item['tipo'],
                'resultado' => $resultado,  // ✅ CORRECTO: 'resultado' no 'estado'
                'situacion' => $item['es_falta']   // ✅ CORRECTO: 'situacion' no 'falta'
                    ? Asistencia::SITUACION_FALTA
                    : Asistencia::SITUACION_NORMAL,
                'latitud' => $item['latitud'],
                'longitud' => $item['longitud'],
                'dentro_rango' => $dentroRango,
                'foto' => $fotoPath,
                'sincronizado' => true,
                // ✅ ELIMINADO: 'turno' (no existe, se obtiene por relación)
            ]);

            Log::info("✅ Asistencia registrada ID {$registro->id} | Rango: " . ($dentroRango ? 'SI' : 'NO'));

            $registradas[] = array_merge($item, [
                'id' => $registro->id,
                'resultado' => $resultado,  // ✅ CORRECTO
                'dentro_rango_calculado' => $dentroRango,
                'foto_guardada' => $fotoPath !== null,
                'archivo' => null
            ]);
        }

        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        Log::info("✅ Sincronización completada: " . count($registradas) . " OK, " . count($omitidas) . " Omitidas");
        Log::info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return response()->json([
            'success' => true,
            'message' => 'Sincronización completada',
            'sincronizadas' => count($registradas),
            'omitidas' => count($omitidas),
            'detalles_omitidas' => $omitidas,
            'detalles_registradas' => $registradas,
        ]);
    }
}