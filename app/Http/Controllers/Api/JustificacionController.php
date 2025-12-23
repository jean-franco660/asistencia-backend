<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Justificacion;
use App\Models\UsuarioApp;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;

class JustificacionController extends Controller
{
    /**
     * Helper: Verifica si el usuario es super_admin o administrador
     * ✅ CORREGIDO: Usar ROL_ADMINISTRADOR
     */
    private function esAdministrador(UsuarioWeb $user): bool
    {
        return in_array($user->rol, [
            UsuarioWeb::ROL_SUPER_ADMIN,
            UsuarioWeb::ROL_ADMINISTRADOR,  // ✅ CORRECTO
        ]);
    }

    /**
     * Helper: Verifica si el supervisor tiene acceso a una institución
     */
    private function supervisorTieneAcceso(UsuarioWeb $user, int $institucionId): bool
    {
        if ($this->esAdministrador($user)) {
            return true;
        }

        return $user->institucionesVigentes()->where('instituciones.id', $institucionId)->exists();
    }

    /**
     * LISTAR JUSTIFICACIONES CON PAGINACIÓN
     * ✅ CORREGIDO: codigo_modular en lugar de codigo_modular_docente
     */
    public function index(Request $request)
    {
        // Validación de filtros
        $request->validate([
            'estado' => 'sometimes|in:PENDIENTE,APROBADO,RECHAZADO',
            'tipo' => 'sometimes|in:ENFERMEDAD,PERMISO_PERSONAL,LICENCIA,COMISION_SERVICIO,CAPACITACION,DUELO,MATERNIDAD,PATERNIDAD,OLVIDO_MARCACION,OTRO',
            'usuario_app_id' => 'sometimes|integer',
            'institucion_id' => 'sometimes|integer',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $user = $request->user();

        $query = Justificacion::with([
            'usuario:id,codigo_modular,apellido_paterno,apellido_materno,nombres',  // ✅ CORRECTO
            'institucion:id,nombre,codigo_modular_ie',
            'revisor:id,nombre,rol',
        ]);

        // Docente (app): solo ve sus justificaciones
        if ($user instanceof UsuarioApp) {
            $query->where('usuario_app_id', $user->id);
        }
        // Usuario web
        else {
            // Super admin y administrador: ven todas las justificaciones
            if (!$this->esAdministrador($user)) {
                // Supervisor: solo ve justificaciones de sus instituciones vigentes
                $institucionesIds = $user->institucionesVigentes()->pluck('id');
                $query->whereIn('institucion_id', $institucionesIds);
            }

            // Filtros adicionales para usuarios web
            if ($request->filled('usuario_app_id')) {
                $query->where('usuario_app_id', (int) $request->usuario_app_id);
            }
        }

        // Filtros comunes
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('institucion_id')) {
            $query->where('institucion_id', (int) $request->institucion_id);
        }

        $perPage = (int) ($request->per_page ?? 20);

        $justificaciones = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Transformar items manteniendo estructura esperada por frontend
        $justificaciones->getCollection()->transform(function (Justificacion $j) {
            return [
                'id' => $j->id,
                'usuario' => $j->usuario ? [
                    'id' => $j->usuario->id,
                    'nombre' => $j->usuario->nombre_completo,
                    'codigo_modular' => $j->usuario->codigo_modular,  // ✅ CORRECTO
                ] : null,
                'institucion' => $j->institucion ? [
                    'id' => $j->institucion->id,
                    'nombre' => $j->institucion->nombre,
                ] : null,
                'tipo' => $j->tipo,
                'fecha_inicio' => $j->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $j->fecha_fin?->format('Y-m-d'),
                'dias' => $j->dias,
                'motivo' => $j->motivo,
                'estado' => $j->estado,
                'estado_badge' => $j->estado_badge,
                'revisor' => $j->revisor ? [
                    'id' => $j->revisor->id,
                    'nombre' => $j->revisor->nombre,
                ] : null,
                'observaciones' => $j->observaciones,
                'fecha_revision' => $j->fecha_revision,
                'created_at' => $j->created_at,
            ];
        });

        return response()->json($justificaciones);
    }

    /**
     * CREAR JUSTIFICACIÓN (solo desde la app móvil)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Solo docentes (UsuarioApp) pueden crear justificaciones
        if (!($user instanceof UsuarioApp)) {
            return response()->json([
                'error' => 'Solo los docentes pueden crear justificaciones desde la app móvil'
            ], 403);
        }

        // Validación
        $request->validate([
            'institucion_id' => 'required|exists:instituciones,id',
            'horario_institucion_id' => 'nullable|exists:horarios_institucion,id', // ✅ Para justificar horario específico
            'tipo' => 'required|in:ENFERMEDAD,PERMISO_PERSONAL,LICENCIA,COMISION_SERVICIO,CAPACITACION,DUELO,MATERNIDAD,PATERNIDAD,OLVIDO_MARCACION,OTRO',
            'fecha_inicio' => 'required|date|before_or_equal:today',  // ✅ No permitir futuro
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio|before_or_equal:today',
            'motivo' => 'required|string|max:1000',
            'asistencia_id' => 'nullable|exists:asistencias,id',
        ]);

        // Validar que la institución pertenezca a las instituciones activas del docente
        $tieneInstitucionActiva = $user->institucionesActivas()
            ->where('instituciones.id', (int) $request->institucion_id)
            ->exists();

        if (!$tieneInstitucionActiva) {
            return response()->json(['error' => 'No autorizado para esa institución'], 403);
        }

        // Validar asistencia_id si se envía
        if ($request->filled('asistencia_id')) {
            $asistenciaOk = Asistencia::where('id', (int) $request->asistencia_id)
                ->where('usuario_app_id', $user->id)
                ->where('institucion_id', (int) $request->institucion_id)
                ->exists();

            if (!$asistenciaOk) {
                return response()->json(['error' => 'La asistencia no corresponde al docente/institución'], 422);
            }
        }

        $justificacion = Justificacion::create([
            'asistencia_id' => $request->asistencia_id,
            'usuario_app_id' => $user->id,
            'institucion_id' => (int) $request->institucion_id,
            'horario_institucion_id' => $request->horario_institucion_id, // ✅ NUEVO
            'tipo' => $request->tipo,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'motivo' => $request->motivo,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Justificación enviada correctamente. Pendiente de revisión.',
            'data' => $justificacion->load(['usuario', 'institucion']),
        ], 201);
    }

    /**
     * APROBAR JUSTIFICACIÓN (solo UsuarioWeb con permisos)
     * ✅ CORREGIDO: Eliminar auditoría manual duplicada - el Trait ya lo maneja
     */
    public function aprobar(Request $request, $id)
    {
        $user = $request->user();

        // Solo usuarios web pueden aprobar
        if (!($user instanceof UsuarioWeb)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $justificacion = Justificacion::with('asistencia')->findOrFail($id);

        // Validar que tenga acceso a la institución
        if (!$this->supervisorTieneAcceso($user, $justificacion->institucion_id)) {
            return response()->json(['error' => 'No tiene permisos para aprobar justificaciones de esta institución'], 403);
        }

        if ($justificacion->estado !== Justificacion::ESTADO_PENDIENTE) {
            return response()->json(['error' => 'Solo se pueden aprobar justificaciones pendientes'], 400);
        }

        $request->validate([
            'observaciones' => 'nullable|string|max:500',
        ]);

        // ✅ SIMPLIFICADO: Solo actualizar, el Trait Auditable registrará automáticamente
        $justificacion->update([
            'estado' => Justificacion::ESTADO_APROBADO,
            'usuario_web_id' => $user->id,
            'observaciones' => $request->observaciones,
            'fecha_revision' => now(),
        ]);

        // 1. Asegurar que existan registros de asistencia para las fechas futuras (Materialización On-Demand)
        $pivot = \App\Models\UsuarioAppInstitucion::where('usuario_app_id', $justificacion->usuario_app_id)
            ->where('institucion_id', $justificacion->institucion_id)
            ->where('estado', 'ACTIVO')
            ->first();

        if ($pivot) {
            $periodo = \Carbon\CarbonPeriod::create($justificacion->fecha_inicio, $justificacion->fecha_fin);
            foreach ($periodo as $date) {
                Asistencia::firstOrCreate(
                    [
                        'usuario_app_id' => $justificacion->usuario_app_id,
                        'institucion_id' => $justificacion->institucion_id,
                        'fecha' => $date->format('Y-m-d'),
                    ],
                    [
                        'horario_institucion_id' => $pivot->horario_institucion_id,
                        'estado_diario' => 'FALTA', // Se actualizará a PRESENTE abajo
                        'observacion' => 'Creado por justificación futura',
                    ]
                );
            }
        }

        // Log de depuración
        \Log::info('🔍 [Justificación] Intentando actualizar asistencias', [
            'justificacion_id' => $justificacion->id,
            'usuario_app_id' => $justificacion->usuario_app_id,
            'institucion_id' => $justificacion->institucion_id,
            'fecha_inicio' => $justificacion->fecha_inicio->format('Y-m-d'),
            'fecha_fin' => $justificacion->fecha_fin->format('Y-m-d'),
        ]);

        // Primero verificar cuántas asistencias existen
        $queryVerificar = Asistencia::where('usuario_app_id', $justificacion->usuario_app_id)
            ->where('institucion_id', $justificacion->institucion_id);

        // ✅ FILTRAR POR HORARIO si está presente
        if ($justificacion->horario_institucion_id) {
            $queryVerificar->where('horario_institucion_id', $justificacion->horario_institucion_id);
        }

        $asistenciasEncontradas = $queryVerificar->whereBetween('fecha', [
            $justificacion->fecha_inicio->format('Y-m-d'),
            $justificacion->fecha_fin->format('Y-m-d')
        ])
            ->get();

        \Log::info('🔍 [Justificación] Asistencias encontradas: ' . $asistenciasEncontradas->count(), [
            'asistencias' => $asistenciasEncontradas->pluck('id', 'fecha')->toArray()
        ]);

        // Actualizar TODAS las asistencias en el rango de fechas
        $queryActualizar = Asistencia::where('usuario_app_id', $justificacion->usuario_app_id)
            ->where('institucion_id', $justificacion->institucion_id); // Misma institución

        // ✅ FILTRAR POR HORARIO si está presente en la justificación
        if ($justificacion->horario_institucion_id) {
            $queryActualizar->where('horario_institucion_id', $justificacion->horario_institucion_id);
            \Log::info('✅ [Justificación] Filtrando por horario específico', [
                'horario_id' => $justificacion->horario_institucion_id
            ]);
        } else {
            \Log::info('⚠️ [Justificación] SIN filtro de horario - actualizará TODOS los horarios de la institución');
        }

        $actualizadas = $queryActualizar->whereBetween('fecha', [
            $justificacion->fecha_inicio->format('Y-m-d'),
            $justificacion->fecha_fin->format('Y-m-d')
        ])
            ->update([
                'estado_diario' => 'PRESENTE',
                'observacion' => 'Justificación Aprobada: ' . $request->observaciones
            ]);

        \Log::info('✅ [Justificación] Asistencias actualizadas a PRESENTE: ' . $actualizadas);

        // ✅ ELIMINADO: auditoría manual duplicada
        // El Trait Auditable ya registró automáticamente el 'updated'
        // Si necesitas una acción personalizada 'aprobado', usar:
        // $justificacion->auditarAccion('aprobado', "...", [...])
        // Pero evita duplicar con el 'updated' automático

        return response()->json([
            'success' => true,
            'message' => 'Justificación aprobada correctamente',
            'data' => $justificacion->fresh()->load(['usuario', 'institucion', 'revisor']),
        ]);
    }

    /**
     * RECHAZAR JUSTIFICACIÓN (solo UsuarioWeb con permisos)
     * ✅ CORREGIDO: Eliminar auditoría manual duplicada
     */
    public function rechazar(Request $request, $id)
    {
        $user = $request->user();

        // Solo usuarios web pueden rechazar
        if (!($user instanceof UsuarioWeb)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $justificacion = Justificacion::findOrFail($id);

        // Validar que tenga acceso a la institución
        if (!$this->supervisorTieneAcceso($user, $justificacion->institucion_id)) {
            return response()->json(['error' => 'No tiene permisos para rechazar justificaciones de esta institución'], 403);
        }

        if ($justificacion->estado !== Justificacion::ESTADO_PENDIENTE) {
            return response()->json(['error' => 'Solo se pueden rechazar justificaciones pendientes'], 400);
        }

        $request->validate([
            'observaciones' => 'required|string|max:500',
        ]);

        // ✅ SIMPLIFICADO: Solo actualizar, el Trait maneja auditoría
        $justificacion->update([
            'estado' => Justificacion::ESTADO_RECHAZADO,
            'usuario_web_id' => $user->id,
            'observaciones' => $request->observaciones,
            'fecha_revision' => now(),
        ]);

        // Actualizar TODAS las asistencias en el rango de fechas a FALTA
        Asistencia::where('usuario_app_id', $justificacion->usuario_app_id)
            ->where('institucion_id', $justificacion->institucion_id)
            ->whereBetween('fecha', [
                $justificacion->fecha_inicio->format('Y-m-d'),
                $justificacion->fecha_fin->format('Y-m-d')
            ])
            ->update([
                'estado_diario' => 'FALTA',
                'observacion' => 'Justificación rechazada: ' . $request->observaciones
            ]);

        // ✅ ELIMINADO: auditoría manual duplicada

        return response()->json([
            'success' => true,
            'message' => 'Justificación rechazada y asistencia marcada como FALTA',
            'data' => $justificacion->fresh()->load(['usuario', 'institucion', 'revisor']),
        ]);
    }

    /**
     * VER DETALLE
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $justificacion = Justificacion::with(['usuario', 'institucion', 'asistencia', 'revisor'])
            ->findOrFail($id);

        // Validar permisos según tipo de usuario
        if ($user instanceof UsuarioApp) {
            // Docente: solo puede ver las suyas
            if ($justificacion->usuario_app_id !== $user->id) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        } else {
            // Usuario web: validar acceso a la institución
            if (!$this->supervisorTieneAcceso($user, $justificacion->institucion_id)) {
                return response()->json(['error' => 'No tiene permisos para ver esta justificación'], 403);
            }
        }

        return response()->json(['data' => $justificacion]);
    }

    /**
     * ELIMINAR JUSTIFICACIÓN
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $justificacion = Justificacion::findOrFail($id);

        // Solo se pueden eliminar justificaciones pendientes
        if ($justificacion->estado !== Justificacion::ESTADO_PENDIENTE) {
            return response()->json(['error' => 'Solo se pueden eliminar justificaciones pendientes'], 400);
        }

        // Validar permisos según tipo de usuario
        if ($user instanceof UsuarioApp) {
            // Docente: solo puede borrar las suyas
            if ($justificacion->usuario_app_id !== $user->id) {
                return response()->json(['error' => 'No autorizado'], 403);
            }
        } else {
            // Usuario web: validar acceso a la institución
            if (!$this->supervisorTieneAcceso($user, $justificacion->institucion_id)) {
                return response()->json(['error' => 'No tiene permisos para eliminar esta justificación'], 403);
            }
        }

        $justificacion->delete();
        // ✅ El Trait Auditable registrará automáticamente el 'deleted'

        return response()->json([
            'success' => true,
            'message' => 'Justificación eliminada correctamente'
        ]);
    }
}