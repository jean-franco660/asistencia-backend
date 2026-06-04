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
 * Gestiona las asistencias desde el panel web.
 *
 * Accesible para administradores y supervisores. Los supervisores solo pueden
 * consultar y operar sobre las instituciones que tienen asignadas de forma vigente.
 * Provee listado granular de marcaciones, cabeceras diarias, revisión de marcaciones
 * observadas, exportación a Excel con auditoría y servicio de fotos de marcación.
 */
class AsistenciaController extends Controller
{
    /**
     * Lista marcaciones individuales (AsistenciaDiaria) con filtros opcionales.
     *
     * Los supervisores solo ven marcaciones de sus instituciones vigentes. Soporta
     * filtros por institución, rango de fechas, estado_marcacion, estado_revision,
     * tipo y búsqueda por nombre o código del docente. Retorna paginado.
     */
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

            // Los siguientes filtros son acumulativos y todos opcionales
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

    /**
     * Lista las cabeceras diarias de asistencia (modelo Asistencia).
     *
     * Cada cabecera agrupa las marcaciones de un docente en un día. Incluye el conteo
     * de marcaciones con estado OBSERVADA y revisión PENDIENTE, útil para priorizar
     * la revisión. Soporta filtros por institución, rango de fechas, estado_diario
     * y búsqueda por docente.
     */
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

            // Los supervisores quedan restringidos a sus instituciones vigentes
            $this->aplicarFiltroInstituciones($query, $user);

            // Los siguientes filtros son acumulativos y todos opcionales
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

    /**
     * Retorna el detalle completo de una cabecera de asistencia incluyendo todas sus marcaciones.
     *
     * Los supervisores solo pueden acceder a asistencias de sus instituciones vigentes;
     * de lo contrario se retorna 403.
     */
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

    /**
     * Retorna el detalle de una marcación individual (AsistenciaDiaria).
     *
     * Incluye los datos del docente, institución, horario y el revisor si ya fue revisada.
     * Los supervisores solo pueden acceder a marcaciones de sus instituciones vigentes.
     */
    public function getMarcacion(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        $marcacion = AsistenciaDiaria::with([
            'asistencia.usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',
            'asistencia.institucion:id,nombre,codigo_modular_ie',
            'asistencia.horario:id,nombre_turno,hora_entrada,hora_salida',
            'revisadoPor:id,nombre,email',
        ])->findOrFail($id);

        // Los supervisores solo acceden a marcaciones de sus instituciones vigentes
        if (!$user->esAdminOSuperAdmin()) {
            $institucionesIds = $user->institucionesVigentes()->pluck('id');
            if (!$institucionesIds->contains($marcacion->asistencia->institucion_id)) {
                return response()->json(['message' => 'No tienes acceso a esta marcación'], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $marcacion]);
    }

    /**
     * Actualiza el estado de revisión de una marcación observada.
     *
     * Estados válidos: PENDIENTE, APROBADA, MANTENER_OBSERVADA. Al aprobar se
     * recalcula el estado diario de la cabecera usando DailyStateCalculatorService.
     * El revisor y la fecha de revisión quedan registrados automáticamente.
     */
    public function updateReview(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'estado_revision' => 'required|in:PENDIENTE,APROBADA,MANTENER_OBSERVADA',
                'observacion' => 'nullable|string|max:500',
            ]);

            $marcacion = AsistenciaDiaria::with('asistencia')->findOrFail($id);

            // Los supervisores solo pueden revisar marcaciones de sus instituciones vigentes
            if (!$user->esAdminOSuperAdmin()) {
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

            // Recalcula el estado diario de la cabecera para reflejar el resultado de la revisión
            if ($marcacion->asistencia) {
                $calculator = app(DailyStateCalculatorService::class);
                $marcacion->asistencia->update($calculator->calculate($marcacion->asistencia));
            }

            return response()->json([
                'success' => true,
                'message' => 'Revisión guardada correctamente',
                'data' => $marcacion->fresh(['asistencia.usuario', 'asistencia.institucion', 'asistencia.horario', 'revisadoPor']),
            ]);

        } catch (\Exception $e) {
            Log::error("Error updateReview: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Exporta asistencias a Excel aplicando los filtros de fecha, institución y tipo.
     *
     * Registra un evento de auditoría con los filtros aplicados, el total de registros
     * exportados y el nombre del archivo generado. Los supervisores quedan
     * restringidos automáticamente a sus instituciones vigentes.
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

        $query = Asistencia::query();

        // Los supervisores quedan restringidos a sus instituciones vigentes
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

        // Registra la exportación para trazabilidad administrativa
        AuditLog::create([
            'actor_id' => $user->id,
            'actor_type' => get_class($user),
            'actor_nombre' => $user->nombre,
            'actor_rol' => $user->rol,
            'accion' => 'exportado',
            'descripcion' => 'Exportación de reporte de asistencias',
            'modelo' => Asistencia::class,
            'modelo_id' => null,
            'modelo_nombre' => 'Reporte de asistencias',
            'metadata' => [
                'total_registros' => $totalRegistros,
                'filtros' => $filters,
                'archivo' => $filename,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'metodo_http' => $request->method(),
        ]);

        return Excel::download(new AsistenciasMultipleExport($filters), $filename);
    }

    /**
     * Exporta el reporte de asistencias de una institución específica.
     *
     * Genera un archivo Excel con el nombre de la institución. Los supervisores
     * solo pueden exportar instituciones que tienen asignadas de forma vigente.
     * Acepta filtros de fecha_inicio y fecha_fin opcionales.
     */
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

    /**
     * Sirve el archivo de foto asociado a una marcación.
     *
     * Retorna 404 si la marcación no tiene foto o si el archivo no existe en disco.
     * Sirve el archivo directamente desde el disco público sin verificar permisos
     * de institución, por lo que debe protegerse a nivel de ruta.
     */
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
