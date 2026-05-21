<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\HorarioCambioLog;
use App\Models\UsuarioApp;
use App\Models\UsuarioAppInstitucion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleManagementController extends Controller
{
    use AuthorizesRequests;
    /**
     * Vista de asignaciones actuales
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UsuarioApp::class);
        $user = $request->user();

        $query = DB::table('usuario_app_institucion as uai')
            ->join('usuarios_app as u', 'u.id', '=', 'uai.usuario_app_id')
            ->join('instituciones as i', 'i.id', '=', 'uai.institucion_id')
            ->join('horarios_institucion as h', 'h.id', '=', 'uai.horario_institucion_id')
            ->whereIn('uai.estado', ['ACTIVO', 'PENDIENTE'])
            ->select([
                'u.id as usuario_id',
                'u.nombres',
                'u.apellido_paterno',
                'u.apellido_materno',
                'i.id as institucion_id',
                'i.nombre as institucion', // Nombre de instituto para mostrar
                'i.codigo_modular_ie',     // Útil para filtro
                DB::raw("GROUP_CONCAT(h.nombre_turno SEPARATOR ', ') as horarios"),
                DB::raw("MAX(uai.updated_at) as ultima_modificacion"),
            ])
            ->groupBy('u.id', 'u.nombres', 'u.apellido_paterno', 'u.apellido_materno', 'i.id', 'i.nombre', 'i.codigo_modular_ie');

        // ✅ SEGURIDAD:: Filtro Obligatorio para Supervisores
        if ($user->esSupervisor()) {
            $institucionesIds = $user->institucionesVigentes()->pluck('id');
            if ($institucionesIds->isEmpty()) {
                return response()->json(['data' => []]); // Sin instituciones, sin resultados
            }
            $query->whereIn('uai.institucion_id', $institucionesIds);
        }

        // ✅ FILTROS DE TEXTO (Frontend envía 'usuario' y 'institucion')
        if ($request->filled('institucion')) {
            $term = $request->institucion;
            $query->where(function ($q) use ($term) {
                $q->where('i.nombre', 'like', "%{$term}%")
                    ->orWhere('i.codigo_modular_ie', 'like', "%{$term}%");
            });
        }

        // Soporte Legacy para ID
        if ($request->filled('institucion_id')) {
            $query->where('uai.institucion_id', $request->institucion_id);
        }

        if ($request->filled('usuario')) {
            $term = $request->usuario;
            $query->where(function ($q) use ($term) {
                $q->where('u.nombres', 'like', "%{$term}%")
                    ->orWhere('u.apellido_paterno', 'like', "%{$term}%")
                    ->orWhere('u.apellido_materno', 'like', "%{$term}%")
                    ->orWhereRaw("CONCAT(u.apellido_paterno, ' ', u.apellido_materno, ' ', u.nombres) LIKE ?", ["%{$term}%"]);
            });
        }

        // Ordenamiento por defecto
        $query->orderBy('u.apellido_paterno')
            ->orderBy('u.nombres');

        return response()->json([
            'data' => $query->paginate(50)
        ]);
    }

    /**
     * Historial de cambios
     */
    public function historial(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UsuarioApp::class);
        $user = $request->user();

        $cambios = HorarioCambioLog::with(['usuario', 'institucion', 'admin'])
            ->orderBy('created_at', 'desc');

        // ✅ SECURITY: Supervisors only see their institutions
        if ($user->esSupervisor()) {
            $institucionesIds = $user->institucionesVigentes()->pluck('id');
            if ($institucionesIds->isEmpty()) {
                return response()->json(['data' => []]);
            }
            $cambios->whereIn('institucion_id', $institucionesIds);
        }

        // Filtros
        if ($request->usuario_id) {
            $cambios->where('usuario_app_id', $request->usuario_id);
        }

        if ($request->institucion_id) {
            $cambios->where('institucion_id', $request->institucion_id);
        }

        if ($request->fecha_desde) {
            $cambios->whereDate('created_at', '>=', $request->fecha_desde);
        }

        if ($request->fecha_hasta) {
            $cambios->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        return response()->json([
            'data' => $cambios->paginate(50)
        ]);
    }

    /**
     * Admin modifica horarios de un usuario
     */
    public function modificarHorarios(Request $request): JsonResponse
    {
        $this->authorize('update', UsuarioApp::class);

        $validated = $request->validate([
            'usuario_app_id' => 'required|exists:usuarios_app,id',
            'institucion_id' => 'required|exists:instituciones,id',
            'horario_ids' => 'required|array',
            'horario_ids.*' => 'exists:horarios_institucion,id',
            'motivo' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            // Obtener horarios actuales
            $actuales = UsuarioAppInstitucion::where('usuario_app_id', $validated['usuario_app_id'])
                ->where('institucion_id', $validated['institucion_id'])
                ->pluck('horario_institucion_id')
                ->toArray();

            // Eliminar asignaciones antiguas
            UsuarioAppInstitucion::where('usuario_app_id', $validated['usuario_app_id'])
                ->where('institucion_id', $validated['institucion_id'])
                ->delete();

            // Crear nuevas asignaciones
            foreach ($validated['horario_ids'] as $horarioId) {
                UsuarioAppInstitucion::create([
                    'usuario_app_id' => $validated['usuario_app_id'],
                    'institucion_id' => $validated['institucion_id'],
                    'horario_institucion_id' => $horarioId,
                    'cargo' => 'DOCENTE',
                    'estado' => 'ACTIVO',
                    'fecha_inicio' => now(),
                ]);
            }

            // Registrar cambio en log
            HorarioCambioLog::create([
                'usuario_app_id' => $validated['usuario_app_id'],
                'institucion_id' => $validated['institucion_id'],
                'horario_anterior' => $actuales,
                'horario_nuevo' => $validated['horario_ids'],
                'origen' => 'ADMIN',
                'usuario_admin_id' => $request->user()->id,
                'motivo' => $validated['motivo'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Horarios modificados correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al modificar horarios: ' . $e->getMessage()
            ], 500);
        }
    }
}
