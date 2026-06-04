<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Expone el registro de auditoría del sistema.
 *
 * Solo accesible para el rol super_admin. Permite consultar, filtrar y obtener
 * estadísticas de las acciones realizadas por cualquier actor sobre cualquier modelo.
 * También expone el historial de cambios de un modelo específico.
 */
class AuditLogController extends Controller
{
    /**
     * Lista los registros de auditoría con filtros acumulativos y paginación.
     *
     * Filtros disponibles: actor_id, actor_type, actor_rol, modelo, modelo_id, accion,
     * solo_criticas (booleano), fecha_desde, fecha_hasta y búsqueda general (buscar).
     * La respuesta transforma cada log para incluir el nombre simple del modelo.
     * Solo accesible para super_admin.
     */
    public function index(Request $request)
    {
        // La restricción es intencional: solo super_admin tiene visibilidad total del log
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $query = AuditLog::query()->orderBy('created_at', 'desc');

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->actor_id);
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->actor_type);
        }

        if ($request->filled('actor_rol')) {
            $query->where('actor_rol', $request->actor_rol);
        }

        if ($request->filled('modelo')) {
            $query->where('modelo', $request->modelo);
        }

        if ($request->filled('modelo_id')) {
            $query->where('modelo_id', $request->modelo_id);
        }

        if ($request->filled('accion')) {
            $query->where('accion', $request->accion);
        }

        if ($request->boolean('solo_criticas')) {
            $query->accionesCriticas();
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('actor_nombre', 'like', "%{$buscar}%")
                  ->orWhere('modelo_nombre', 'like', "%{$buscar}%")
                  ->orWhere('descripcion', 'like', "%{$buscar}%");
            });
        }

        $logs = $query->paginate($request->get('per_page', 20));

        // Normaliza el nombre del modelo a su forma simple para facilitar la lectura en el frontend
        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'actor' => [
                    'id' => $log->actor_id,
                    'type' => $log->actor_type,
                    'nombre' => $log->actor_nombre,
                    'rol' => $log->actor_rol,
                ],
                'accion' => $log->accion,
                'descripcion' => $log->descripcion,
                'modelo' => [
                    'type' => class_basename($log->modelo),
                    'id' => $log->modelo_id,
                    'nombre' => $log->modelo_nombre,
                ],
                'cambios' => $log->cambios,
                'metadata' => $log->metadata,
                'contexto' => [
                    'ip' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'metodo_http' => $log->metodo_http,
                ],
                'created_at' => $log->created_at,
            ];
        });

        return response()->json($logs);
    }

    /**
     * Retorna el detalle completo de un registro de auditoría, incluyendo
     * datos anteriores, datos nuevos y diferencias calculadas.
     * Solo accesible para super_admin.
     */
    public function show(Request $request, $id)
    {
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $log = AuditLog::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $log->id,
                'actor' => [
                    'id' => $log->actor_id,
                    'type' => $log->actor_type,
                    'nombre' => $log->actor_nombre,
                    'rol' => $log->actor_rol,
                ],
                'accion' => $log->accion,
                'descripcion' => $log->descripcion,
                'modelo' => [
                    'type' => $log->modelo,
                    'type_simple' => class_basename($log->modelo),
                    'id' => $log->modelo_id,
                    'nombre' => $log->modelo_nombre,
                ],
                'datos_anteriores' => $log->datos_anteriores,
                'datos_nuevos' => $log->datos_nuevos,
                'cambios' => $log->cambios,
                'metadata' => $log->metadata,
                'contexto' => [
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'url' => $log->url,
                    'metodo_http' => $log->metodo_http,
                ],
                'created_at' => $log->created_at,
            ]
        ]);
    }

    /**
     * Retorna estadísticas agregadas del registro de auditoría.
     *
     * El parámetro 'periodo' define la ventana de días a analizar (por defecto 7).
     * Incluye total de logs, acciones críticas, top de actores, acciones y modelos
     * más frecuentes en el período. Solo accesible para super_admin.
     */
    public function stats(Request $request)
    {
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $periodo = $request->get('periodo', 7); // ventana de análisis en días

        $stats = [
            'total_logs' => AuditLog::count(),
            'logs_periodo' => AuditLog::where('created_at', '>=', now()->subDays($periodo))->count(),
            'acciones_criticas_periodo' => AuditLog::accionesCriticas()
                ->where('created_at', '>=', now()->subDays($periodo))
                ->count(),
            'top_actores' => AuditLog::where('created_at', '>=', now()->subDays($periodo))
                ->selectRaw('actor_nombre, actor_rol, COUNT(*) as total')
                ->whereNotNull('actor_id')
                ->groupBy('actor_nombre', 'actor_rol')
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
            'top_acciones' => AuditLog::where('created_at', '>=', now()->subDays($periodo))
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->orderByDesc('total')
                ->get(),
            'top_modelos' => AuditLog::where('created_at', '>=', now()->subDays($periodo))
                ->selectRaw('modelo, COUNT(*) as total')
                ->groupBy('modelo')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) {
                    return [
                        'modelo' => class_basename($item->modelo),
                        'total' => $item->total,
                    ];
                }),
        ];

        return response()->json($stats);
    }

    /**
     * Retorna el historial de cambios de un registro específico de un modelo.
     *
     * El parámetro $modelo acepta el nombre simple de la clase (ej. 'UsuarioWeb')
     * y lo resuelve al namespace completo mediante un mapa interno. Si el nombre
     * no está en el mapa, se usa tal cual como clase completa.
     * Solo accesible para super_admin.
     */
    public function historialModelo(Request $request, $modelo, $id)
    {
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Permite recibir el nombre simple y resolverlo al namespace completo
        $modeloMap = [
            'UsuarioWeb' => 'App\Models\UsuarioWeb',
            'UsuarioApp' => 'App\Models\UsuarioApp',
            'Institucion' => 'App\Models\Institucion',
            'HorarioInstitucion' => 'App\Models\HorarioInstitucion',
            'Justificacion' => 'App\Models\Justificacion',
            'Feriado' => 'App\Models\Feriado',
        ];

        $modeloCompleto = $modeloMap[$modelo] ?? $modelo;

        $logs = AuditLog::where('modelo', $modeloCompleto)
            ->where('modelo_id', $id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'accion' => $log->accion,
                    'descripcion' => $log->descripcion,
                    'actor_nombre' => $log->actor_nombre,
                    'cambios' => $log->cambios,
                    'created_at' => $log->created_at,
                ];
            });

        return response()->json(['data' => $logs]);
    }
}