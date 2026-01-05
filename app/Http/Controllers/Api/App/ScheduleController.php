<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\HorarioCambioLog;
use App\Models\HorarioInstitucion;
use App\Models\UsuarioAppInstitucion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    /**
     * Obtiene horarios disponibles y actuales del usuario
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
     * Usuario actualiza sus horarios
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
        $nuevosHorarios = $validated['horario_ids'];

        DB::beginTransaction();
        try {
            // ✅ Verificar si el usuario ya cambió sus horarios este mes
            $primerDiaMes = now()->startOfMonth();
            $ultimoCambioEsteMes = HorarioCambioLog::where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->where('origen', 'APP') // Solo cambios desde la app, no desde admin
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

            // Obtener datos actuales incluyendo cargo (usando withTrashed para ver todo)
            $asignacionesActuales = UsuarioAppInstitucion::withTrashed()
                ->where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->get();

            $actuales = $asignacionesActuales->pluck('horario_institucion_id')->unique()->filter()->values()->toArray();

            // Preservar el cargo del usuario (tomar el primero que no sea null)
            $cargo = $asignacionesActuales->pluck('cargo')->filter()->first() ?? 'DOCENTE';

            // FORCE DELETE para evitar errores de clave única con soft deleted rows
            UsuarioAppInstitucion::withTrashed()
                ->where('usuario_app_id', $user->id)
                ->where('institucion_id', $institucionId)
                ->forceDelete();

            // Crear nuevas asignaciones con el cargo preservado
            foreach ($nuevosHorarios as $horarioId) {
                UsuarioAppInstitucion::create([
                    'usuario_app_id' => $user->id,
                    'institucion_id' => $institucionId,
                    'horario_institucion_id' => $horarioId,
                    'cargo' => $cargo, // ✅ Cargo preservado
                    'estado' => 'ACTIVO',
                    'fecha_inicio' => now(),
                ]);
            }

            // Registrar cambio en log
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
