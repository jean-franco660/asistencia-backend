<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Listar logs de auditoría con filtros y paginación
     */
    public function index(Request $request)
    {
        // Solo super_admin y administrador pueden ver logs
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $query = AuditLog::query()->orderBy('created_at', 'desc');

        // Filtro por actor (quien realizó la acción)
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->actor_id);
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->actor_type);
        }

        if ($request->filled('actor_rol')) {
            $query->where('actor_rol', $request->actor_rol);
        }

        // Filtro por modelo afectado
        if ($request->filled('modelo')) {
            $query->where('modelo', $request->modelo);
        }

        if ($request->filled('modelo_id')) {
            $query->where('modelo_id', $request->modelo_id);
        }

        // Filtro por acción
        if ($request->filled('accion')) {
            $query->where('accion', $request->accion);
        }

        // Filtro por acciones críticas
        if ($request->boolean('solo_criticas')) {
            $query->accionesCriticas();
        }

        // Filtro por rango de fechas
        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        // Búsqueda general
        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('actor_nombre', 'like', "%{$buscar}%")
                  ->orWhere('modelo_nombre', 'like', "%{$buscar}%")
                  ->orWhere('descripcion', 'like', "%{$buscar}%");
            });
        }

        $logs = $query->paginate($request->get('per_page', 20));

        // Transformar para incluir cambios legibles
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
     * Ver detalle de un log específico
     */
    public function show(Request $request, $id)
    {
        // Solo super_admin y administrador
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
     * Estadísticas de auditoría
     */
    public function stats(Request $request)
    {
        // Solo super_admin y administrador
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $periodo = $request->get('periodo', 7); // días

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
     * Historial de un modelo específico
     */
    public function historialModelo(Request $request, $modelo, $id)
    {
        // Solo super_admin y administrador
        if (!in_array($request->user()->rol, ['super_admin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Mapear nombre simple a clase completa
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