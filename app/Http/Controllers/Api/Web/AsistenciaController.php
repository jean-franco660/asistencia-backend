<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\AsistenciaResource;
use App\Exports\AsistenciasMultipleExport;
use App\Exports\InstitucionReportExport;
use App\Models\Asistencia;
use App\Models\AsistenciaDiaria;
use App\Models\AuditLog;
use App\Models\Institucion;
use App\Models\UsuarioWeb;
use App\Services\DailyStateCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Controlador de Asistencias para el PANEL WEB (Administradores/Supervisores)
 *
 * Responsabilidades:
 * - Listar marcaciones individuales (index)
 * - Listar cabeceras diarias (listCabeceras)
 * - Ver detalle de asistencia (show)
 * - Obtener marcación individual (getMarcacion)
 * - Revisión de marcaciones (updateReview)
 * - Exportar reportes (exportar, exportarInstitucion)
 * - Servir foto de marcación (foto)
 */
class AsistenciaController extends Controller
{
    /* ================================================================
     * LISTAR MARCACIONES (AsistenciaDiaria) — Vista granular
     * ================================================================ */

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = AsistenciaDiaria::query()
                ->with([
                    'asistencia.usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
                    'asistencia.institucion:id,nombre,codigo_modular_ie',
                    'asistencia.horario:id,nombre_turno,hora_entrada,hora_salida',
                    'revisadoPor:id,nombre,email',
                ])
                ->join('asistencias', 'asistencias_diarias.asistencia_id', '=', 'asistencias.id')
                ->select('asistencias_diarias.*')
                ->orderByDesc('marcada_en');

            // Filtro obligatorio: supervisores solo ven sus instituciones
            $this->aplicarFiltroInstituciones($query, $user, 'asistencias.institucion_id');

            // Filtros opcionales
            if ($request->filled('institucion_id')) {
                $this->validarAccesoInstitucion($user, $request->institucion_id);
                $query->where('asistencias.institucion_id', $request->institucion_id);
            }

            if ($request->filled('fecha_inicio')) {
                $query->whereDate('marcada_en', '>=', $request->fecha_inicio);
            }
            if ($request->filled('fecha_fin')) {
                $query->whereDate('marcada_en', '<=', $request->fecha_fin);
            }
            if ($request->filled('estado_marcacion')) {
                $query->where('asistencias_diarias.estado_marcacion', $request->estado_marcacion);
            }
            if ($request->filled('estado_revision')) {
                $query->where('asistencias_diarias.estado_revision', $request->estado_revision);
            }
            if ($request->filled('tipo')) {
                $query->where('asistencias_diarias.tipo', $request->tipo);
            }
            if ($request->filled('search')) {
                $this->aplicarBusquedaDocente($query, $request->search, 'asistencia.usuario');
            }

            $perPage = $request->input('per_page', 20);
            return response()->json(['success' => true, 'data' => $query->paginate($perPage)]);

        } catch (\Exception $e) {
            Log::error("Error index asistencias: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================
     * LISTAR CABECERAS DIARIAS — Vista principal
     * ================================================================ */

    public function listCabeceras(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = Asistencia::query()
                ->with([
                    'usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
                    'institucion:id,nombre,codigo_modular_ie',
                    'horario:id,nombre_turno,hora_entrada,hora_salida',
                ])
                ->orderByDesc('fecha')
                ->orderByDesc('created_at');

            // Filtro obligatorio de instituciones
            $this->aplicarFiltroInstituciones($query, $user);

            // Filtros opcionales
            if ($request->filled('institucion_id')) {
                $this->validarAccesoInstitucion($user, $request->institucion_id);
                $query->where('institucion_id', $request->institucion_id);
            }
            if ($request->filled('fecha_inicio')) {
                $query->whereDate('fecha', '>=', $request->fecha_inicio);
            }
            if ($request->filled('fecha_fin')) {
                $query->whereDate('fecha', '<=', $request->fecha_fin);
            }
            if ($request->filled('estado_diario')) {
                $query->where('estado_diario', $request->estado_diario);
            }
            if ($request->filled('search')) {
                $this->aplicarBusquedaDocente($query, $request->search);
            }

            // Conteo de marcaciones pendientes de revisión
            $query->withCount([
                'marcaciones as marcaciones_pendientes' => fn ($q) => $q
                    ->where('estado_marcacion', 'OBSERVADA')
                    ->where('estado_revision', 'PENDIENTE'),
            ]);

            $perPage   = $request->input('per_page', 20);
            $cabeceras = $query->paginate($perPage);

            return AsistenciaResource::collection($cabeceras)->response();

        } catch (\Exception $e) {
            Log::error("Error listCabeceras: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================
     * VER DETALLE DE ASISTENCIA
     * ================================================================ */

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        $asistencia = Asistencia::with([
            'usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres,dni',
            'institucion:id,nombre,codigo_modular_ie',
            'horario:id,nombre_turno,hora_entrada,hora_salida',
            'marcaciones',
        ])->findOrFail($id);

        // Validar acceso
        if (!$user->esAdminOSuperAdmin()) {
            $institucionesIds = $user->institucionesVigentes()->pluck('id');
            if (!$institucionesIds->contains($asistencia->institucion_id)) {
                return response()->json(['message' => 'No tienes acceso a esta asistencia'], 403);
            }
        }

        return response()->json(['success' => true, 'data' => new AsistenciaResource($asistencia)]);
    }

    /* ================================================================
     * OBTENER MARCACIÓN INDIVIDUAL
     * ================================================================ */

    public function getMarcacion(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        $marcacion = AsistenciaDiaria::with([
            'asistencia.usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
            'asistencia.institucion:id,nombre,codigo_modular_ie',
            'asistencia.horario:id,nombre_turno,hora_entrada,hora_salida',
            'revisadoPor:id,nombre,email',
        ])->findOrFail($id);

        // Validar acceso
        if (!$user->esAdminOSuperAdmin()) {
            $institucionesIds = $user->institucionesVigentes()->pluck('id');
            if (!$institucionesIds->contains($marcacion->asistencia->institucion_id)) {
                return response()->json(['message' => 'No tienes acceso a esta marcación'], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $marcacion]);
    }

    /* ================================================================
     * REVISIÓN DE MARCACIÓN
     * ================================================================ */

    public function updateReview(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'estado_revision' => 'required|in:PENDIENTE,APROBADA,MANTENER_OBSERVADA',
                'observacion'     => 'nullable|string|max:500',
            ]);

            $marcacion = AsistenciaDiaria::with('asistencia')->findOrFail($id);

            // Validar acceso
            if (!$user->esAdminOSuperAdmin()) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                if (!$institucionesIds->contains($marcacion->asistencia->institucion_id)) {
                    return response()->json(['message' => 'No tienes acceso a esta marcación'], 403);
                }
            }

            $marcacion->update([
                'estado_revision'            => $request->estado_revision,
                'revision_observacion'       => $request->observacion,
                'revisado_por_usuario_web_id' => $user->id,
                'revisado_en'                => now(),
            ]);

            // Recalcular estado diario
            if ($marcacion->asistencia) {
                $calculator = app(DailyStateCalculatorService::class);
                $marcacion->asistencia->update($calculator->calculate($marcacion->asistencia));
            }

            return response()->json([
                'success' => true,
                'message' => 'Revisión guardada correctamente',
                'data'    => $marcacion->fresh(['asistencia.usuario', 'asistencia.institucion', 'asistencia.horario', 'revisadoPor']),
            ]);

        } catch (\Exception $e) {
            Log::error("Error updateReview: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================
     * EXPORTAR ASISTENCIAS (con auditoría)
     * ================================================================ */

    public function exportar(Request $request)
    {
        $user = $request->user();

        $filters = [
            'fecha_inicio'   => $request->input('fecha_inicio'),
            'fecha_fin'      => $request->input('fecha_fin'),
            'institucion_id' => $request->input('institucion_id'),
            'tipo'           => $request->input('tipo'),
            'user'           => $user,
        ];

        $query = Asistencia::query();

        // Filtro obligatorio
        $this->aplicarFiltroInstituciones($query, $user);

        if ($filters['fecha_inicio']) $query->whereDate('fecha', '>=', $filters['fecha_inicio']);
        if ($filters['fecha_fin'])    $query->whereDate('fecha', '<=', $filters['fecha_fin']);

        if ($filters['institucion_id']) {
            $this->validarAccesoInstitucion($user, $filters['institucion_id']);
            $query->where('institucion_id', $filters['institucion_id']);
        }

        if ($filters['tipo']) $query->where('tipo', $filters['tipo']);

        $totalRegistros = $query->count();
        $filename       = 'Reporte_Asistencias_' . now()->format('Y-m-d_His') . '.xlsx';

        // Auditoría
        AuditLog::create([
            'actor_id'      => $user->id,
            'actor_type'    => get_class($user),
            'actor_nombre'  => $user->nombre,
            'actor_rol'     => $user->rol,
            'accion'        => 'exportado',
            'descripcion'   => 'Exportación de reporte de asistencias',
            'modelo'        => Asistencia::class,
            'modelo_id'     => null,
            'modelo_nombre' => 'Reporte de asistencias',
            'metadata'      => [
                'total_registros' => $totalRegistros,
                'filtros'         => $filters,
                'archivo'         => $filename,
            ],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'url'         => $request->fullUrl(),
            'metodo_http' => $request->method(),
        ]);

        return Excel::download(new AsistenciasMultipleExport($filters), $filename);
    }

    /* ================================================================
     * EXPORTAR POR INSTITUCIÓN
     * ================================================================ */

    public function exportarInstitucion(Request $request, $institucionId)
    {
        try {
            $user = $request->user();

            if (!$user->esAdminOSuperAdmin()) {
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                if (!$institucionesIds->contains($institucionId)) {
                    return response()->json(['message' => 'No tienes acceso a esta institución'], 403);
                }
            }

            $institucion = Institucion::findOrFail($institucionId);
            $filename    = 'Reporte_' . Str::slug($institucion->nombre) . '_' . now()->format('Ymd_His') . '.xlsx';

            return Excel::download(
                new InstitucionReportExport($institucionId, $request->input('fecha_inicio'), $request->input('fecha_fin')),
                $filename
            );

        } catch (\Exception $e) {
            Log::error("Error exportarInstitucion: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ================================================================
     * SERVIR FOTO DE MARCACIÓN
     * ================================================================ */

    public function foto($id)
    {
        $marcacion = AsistenciaDiaria::findOrFail($id);

        if (empty($marcacion->foto_url)) {
            return response()->json(['message' => 'No tiene foto'], 404);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($marcacion->foto_url)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        return response()->file($disk->path($marcacion->foto_url));
    }

    /* ================================================================
     * MÉTODOS PRIVADOS REUTILIZABLES
     * ================================================================ */

    /**
     * Aplica filtro de instituciones basado en el rol del usuario.
     * Admins ven todo; supervisores solo sus instituciones vigentes.
     */
    private function aplicarFiltroInstituciones($query, UsuarioWeb $user, string $column = 'institucion_id'): void
    {
        if ($user->esAdminOSuperAdmin()) return;

        $institucionesIds = $user->institucionesVigentes()->pluck('id');

        if ($institucionesIds->isEmpty()) {
            // Forzar resultado vacío sin lanzar excepción
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($column, $institucionesIds);
    }

    /**
     * Valida que el usuario tenga acceso a una institución específica.
     * Lanza excepción HTTP 403 si no tiene acceso.
     */
    private function validarAccesoInstitucion(UsuarioWeb $user, int $institucionId): void
    {
        if ($user->esAdminOSuperAdmin()) return;

        $institucionesIds = $user->institucionesVigentes()->pluck('id');
        if (!$institucionesIds->contains($institucionId)) {
            abort(403, 'Sin acceso a esta institución');
        }
    }

    /**
     * Aplica búsqueda por nombre/código del docente.
     */
    private function aplicarBusquedaDocente($query, string $search, string $relation = 'usuario'): void
    {
        $query->whereHas($relation, function ($q) use ($search) {
            $q->where('codigo_modular', 'like', "%{$search}%")
              ->orWhere('nombres', 'like', "%{$search}%")
              ->orWhere('apellido_paterno', 'like', "%{$search}%")
              ->orWhere('apellido_materno', 'like', "%{$search}%")
              ->orWhereRaw("CONCAT(apellido_paterno, ' ', apellido_materno, ' ', nombres) LIKE ?", ["%{$search}%"]);
        });
    }
}
