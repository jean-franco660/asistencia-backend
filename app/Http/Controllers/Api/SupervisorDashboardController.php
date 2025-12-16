<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
                    'total_docentes' => 0,
                    'asistencias_hoy' => 0,
                    'ausencias_hoy' => 0,
                    'justificaciones_pendientes' => 0,
                ],
            ]);
        }

        /* ============================================================
           📌 ESTADÍSTICAS AGREGADAS
        ============================================================ */

        // Total de docentes en todas las instituciones del supervisor
        $totalDocentes = \App\Models\UsuarioApp::whereHas(
            'instituciones',
            fn($q) => $q->whereIn('instituciones.id', $institucionesIds)
        )->count();

        // Asistencias de hoy (entrada + salida)
        $asistenciasHoy = \App\Models\Asistencia::whereIn('institucion_id', $institucionesIds)
            ->whereDate('fecha_hora', $today)
            ->count();

        // Contar docentes con asistencia completa hoy
        $registrosHoy = \App\Models\Asistencia::whereIn('institucion_id', $institucionesIds)
            ->whereDate('fecha_hora', $today)
            ->get()
            ->groupBy('usuario_app_id');

        $docentesPresentes = 0;
        foreach ($registrosHoy as $userId => $registros) {
            $entrada = $registros->firstWhere('tipo', 'entrada');
            $salida = $registros->firstWhere('tipo', 'salida');
            if ($entrada && $salida) {
                $docentesPresentes++;
            }
        }

        $ausenciasHoy = max(0, $totalDocentes - $docentesPresentes);

        // Justificaciones pendientes
        $justificacionesPendientes = \App\Models\Justificacion::where('estado', 'PENDIENTE')
            ->whereIn('institucion_id', $institucionesIds)
            ->count();

        /* ============================================================
           📌 DESGLOSE POR INSTITUCIÓN
        ============================================================ */

        $institucionesData = $instituciones->map(function ($institucion) use ($today) {
            $docentesCount = $institucion->docentes()->count();

            $asistenciasHoyInst = \App\Models\Asistencia::where('institucion_id', $institucion->id)
                ->whereDate('fecha_hora', $today)
                ->count();

            return [
                'id' => $institucion->id,
                'nombre' => $institucion->nombre,
                'codigo_modular' => $institucion->codigo_modular_ie,
                'docentes' => $docentesCount,
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
                'total_docentes' => $totalDocentes,
                'asistencias_hoy' => $docentesPresentes,
                'ausencias_hoy' => $ausenciasHoy,
                'justificaciones_pendientes' => $justificacionesPendientes,
                'registros_asistencia_hoy' => $asistenciasHoy, // Total de marcaciones
            ],
        ]);
    }
}
