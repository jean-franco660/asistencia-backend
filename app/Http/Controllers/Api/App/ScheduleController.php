<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\HorarioCambioLog;
use App\Models\HorarioInstitucion;
use App\Models\UsuarioAppInstitucion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona la consulta y modificación de horarios asignados al docente desde la app móvil.
 *
 * Permite al docente ver sus horarios actuales por institución y actualizarlos,
 * sujeto a la restricción de un solo cambio por mes por institución.
 * Solo acceden docentes autenticados con token Sanctum.
 */
class ScheduleController extends Controller
{
    /**
     * Retorna los horarios disponibles y los actualmente seleccionados por el docente,
     * agrupados por institución.
     *
     * Para cada institución asignada incluye todos los horarios activos disponibles
     * y los identificadores de los horarios a los que el docente está vinculado.
     */
    public function getMisHorarios(Request $request): JsonResponse
    {
        $user = $request->user();

        $instituciones = UsuarioAppInstitucion::where('usuario_app_id', $user->id)
            ->with(['institucion', 'horario'])
            ->get()
            ->groupBy('institucion_id');

        $response = [];

        foreach ($instituciones as $institucionId => $asignaciones) {
            $institucion = $asignaciones->first()->institucion;

            $horariosDisponibles = HorarioInstitucion::where('institucion_id', $institucionId)
                ->where('activo', true)
                ->get();

            $horariosActuales = $asignaciones->pluck('horario_institucion_id')->filter()->toArray();

            $response[] = [
                'institucion' => $institucion,
                'horarios_disponibles' => $horariosDisponibles,
                'horarios_seleccionados' => $horariosActuales,
            ];
        }

        return response()->json(['data' => $response]);
    }

    /**
     * Actualiza los horarios del docente en una institución determinada.
     *
     * Aplica un límite de un cambio por mes por institución para cambios originados
     * desde la app (origen APP). Los cambios realizados por administradores no cuentan
     * para este límite. Elimina físicamente las asignaciones anteriores (forceDelete)
     * para evitar conflictos de clave única con registros en soft delete, y preserva
     * el cargo del docente al recrear las asignaciones. Registra el cambio en el log
     * de historial de horarios.
     */
    public function actualizarHorarios(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institucion_id' => 'required|exists:instituciones,id',
            'horario_ids' => 'required|array',
            'horario_ids.*' => 'exists:horarios_institucion,id',
        ]);

        $user = $request->user();
        $institucionId = $validated['institucion_id'];
        $nuevosHorarios = array_unique($validated['horario_ids']); // Descarta horarios repetidos en la solicitud

        DB::beginTransaction();
        try {
            // Solo se contabilizan los cambios iniciados por el propio docente, no los del administrador
            $primerDiaMes = now()->startOfMonth();
            $ultimoCambioEsteMes = HorarioCambioLog::where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->where('origen', 'APP')
                ->where('created_at', '>=', $primerDiaMes)
                ->exists();

            if ($ultimoCambioEsteMes) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Ya realizaste un cambio de horarios este mes. Solo puedes cambiar tus horarios una vez al mes.',
                    'error_code' => 'MONTHLY_LIMIT_REACHED'
                ], 403);
            }

            // Incluye registros eliminados para conservar el historial de cargo
            $asignacionesActuales = UsuarioAppInstitucion::withTrashed()
                ->where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->get();

            $actuales = $asignacionesActuales->pluck('horario_institucion_id')->unique()->filter()->values()->toArray();

            // El cargo se mantiene para no perder la categoría del docente al regenerar las asignaciones
            $cargo = $asignacionesActuales->pluck('cargo')->filter()->first() ?? 'DOCENTE';

            // El forceDelete evita que los registros en soft delete bloqueen la inserción por clave única
            UsuarioAppInstitucion::withTrashed()
                ->where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->forceDelete();

            // Recrea las asignaciones con el cargo original del docente
            foreach ($nuevosHorarios as $horarioId) {
                UsuarioAppInstitucion::updateOrCreate(
                    [
                        'usuario_app_id' => $user->id,
                        'institucion_id' => $institucionId,
                        'horario_institucion_id' => $horarioId,
                    ],
                    [
                        'cargo' => $cargo,
                        'estado' => 'ACTIVO',
                        'fecha_inicio' => now(),
                    ]
                );
            }

            // Persiste el historial de cambio para auditoría y aplicación del límite mensual
            HorarioCambioLog::create([
                'usuario_app_id' => $user->id,
                'institucion_id' => $institucionId,
                'horario_anterior' => $actuales,
                'horario_nuevo' => $nuevosHorarios,
                'origen' => 'APP',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Horarios actualizados correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error("Error actualizando horarios APP: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar horarios: ' . $e->getMessage()
            ], 500);
        }
    }
}
