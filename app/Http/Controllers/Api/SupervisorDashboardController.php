<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use Illuminate\Http\Request;

class SupervisorDashboardController extends Controller
{
    /**
     * Dashboard para supervisores
     * Muestra estadísticas de todas las instituciones asignadas al supervisor
     */
    public function index(Request $request)
    {
        $supervisor = $request->user();
        $today = \Carbon\Carbon::today();

        // Obtener todas las instituciones del supervisor
        $instituciones = $supervisor->instituciones;
        $institucionesIds = $instituciones->pluck('id');

        // Si no tiene instituciones asignadas
        if ($institucionesIds->isEmpty()) {
            return response()->json([
                'instituciones' => [],
                'resumen' => [
                    'total_instituciones' => 0,
                    'total_Usuarios_App' => 0,
                    'asistencias_hoy' => 0,
                    'ausencias_hoy' => 0,
                    'justificaciones_pendientes' => 0,
                ],
            ]);
        }

        /* ============================================================
           📌 ESTADÍSTICAS AGREGADAS
        ============================================================ */

        // Total de Usuarios_App en todas las instituciones del supervisor
        $total_UsuariosAPP = \App\Models\UsuarioApp::whereHas(
            'instituciones',
            fn($q) => $q->whereIn('instituciones.id', $institucionesIds)
        )->count();

        // Asistencias de hoy (entrada + salida)
        $asistenciasHoy = Asistencia::whereIn('institucion_id', $institucionesIds)
            ->whereDate('fecha_hora', $today)
            ->count();

        // Contar Usuarios_App con asistencia completa hoy
        $registrosHoy = Asistencia::whereIn('institucion_id', $institucionesIds)
            ->whereDate('fecha_hora', $today)
            ->get()
            ->groupBy('usuario_app_id');

        $usuariosAPP_Presentes = 0;
        foreach ($registrosHoy as $userId => $registros) {
            $entrada = $registros->firstWhere('tipo', Asistencia::TIPO_ENTRADA);
            $salida = $registros->firstWhere('tipo', Asistencia::TIPO_SALIDA);
            if ($entrada && $salida) {
                $usuariosAPP_Presentes++;
            }
        }

        $ausenciasHoy = max(0, $total_UsuariosAPP - $usuariosAPP_Presentes);

        // Justificaciones pendientes
        $justificacionesPendientes = \App\Models\Justificacion::where('estado', 'PENDIENTE')
            ->whereIn('institucion_id', $institucionesIds)
            ->count();

        /* ============================================================
           📌 DESGLOSE POR INSTITUCIÓN
        ============================================================ */

        $institucionesData = $instituciones->map(function ($institucion) use ($today) {
            $UsuariosAPP_Count = $institucion->Usuarios_App()->count();

            $asistenciasHoyInst = Asistencia::where('institucion_id', $institucion->id)
                ->whereDate('fecha_hora', $today)
                ->count();

            return [
                'id' => $institucion->id,
                'nombre' => $institucion->nombre,
                'codigo_modular' => $institucion->codigo_modular_ie,
                'usuarios_APP' => $UsuariosAPP_Count,
                'asistencias_hoy' => $asistenciasHoyInst,
            ];
        });

        /* ============================================================
           📌 RESPUESTA FINAL
        ============================================================ */

        return response()->json([
            'instituciones' => $institucionesData,
            'resumen' => [
                'total_instituciones' => $instituciones->count(),
                'total_Usuarios_App' => $total_UsuariosAPP,
                'asistencias_hoy' => $usuariosAPP_Presentes,
                'ausencias_hoy' => $ausenciasHoy,
                'justificaciones_pendientes' => $justificacionesPendientes,
                'registros_asistencia_hoy' => $asistenciasHoy, // Total de marcaciones
            ],
        ]);
    }
}
