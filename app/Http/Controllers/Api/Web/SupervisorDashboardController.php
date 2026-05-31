<?php

namespace App\Http\Controllers\Api\Web;

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
                    'total_usuarios_app' => 0,  //  snake_case
                    'asistencias_hoy' => 0,
                    'ausencias_hoy' => 0,
                    'justificaciones_pendientes' => 0,
                ],
            ]);
        }

        /* ============================================================
            ESTADÍSTICAS AGREGADAS
        ============================================================ */

        // Total de usuarios app en todas las instituciones del supervisor
        $totalUsuariosApp = \App\Models\UsuarioApp::whereHas(  //  camelCase
            'instituciones',
            fn($q) => $q->whereIn('instituciones.id', $institucionesIds)
        )->count();

        // Asistencias de hoy (Headers)
        $asistenciasHoy = Asistencia::whereIn('institucion_id', $institucionesIds)
            ->whereDate('fecha', $today)
            ->count();

        // Contar usuarios app con asistencia completa hoy (estado PRESENTE o TARDANZA)
        $registrosHoy = Asistencia::whereIn('institucion_id', $institucionesIds)
            ->whereDate('fecha', $today)
            ->get()
            ->groupBy('usuario_app_id');

        $usuariosAppPresentes = 0;  //  camelCase
        foreach ($registrosHoy as $userId => $registros) {
            $registro = $registros->first();
            if ($registro && in_array($registro->estado_diario, ['PRESENTE', 'TARDANZA'])) {
                $usuariosAppPresentes++;
            }
        }

        $ausenciasHoy = max(0, $totalUsuariosApp - $usuariosAppPresentes);

        // Justificaciones pendientes
        $justificacionesPendientes = \App\Models\Justificacion::where('estado', 'PENDIENTE')
            ->whereIn('institucion_id', $institucionesIds)
            ->count();

        /* ============================================================
            DESGLOSE POR INSTITUCIÓN
        ============================================================ */

        $institucionesData = $instituciones->map(function ($institucion) use ($today) {
            $usuariosAppCount = $institucion->usuariosApp()->count();  //  camelCase

            $asistenciasHoyInst = Asistencia::where('institucion_id', $institucion->id)
                ->whereDate('fecha', $today)
                ->count();

            return [
                'id' => $institucion->id,
                'nombre' => $institucion->nombre,
                'codigo_modular' => $institucion->codigo_modular_ie,
                'usuarios_app' => $usuariosAppCount,  //  snake_case para JSON
                'asistencias_hoy' => $asistenciasHoyInst,
            ];
        });

        /* ============================================================
            RESPUESTA FINAL
        ============================================================ */

        return response()->json([
            'instituciones' => $institucionesData,
            'resumen' => [
                'total_instituciones' => $instituciones->count(),
                'total_usuarios_app' => $totalUsuariosApp,  //  snake_case
                'asistencias_hoy' => $usuariosAppPresentes,
                'ausencias_hoy' => $ausenciasHoy,
                'justificaciones_pendientes' => $justificacionesPendientes,
                'registros_asistencia_hoy' => $asistenciasHoy,
            ],
        ]);
    }
}