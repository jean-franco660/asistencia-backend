<?php

namespace App\Http\Controllers\Api;

use App\Exports\AsistenciasMultipleExport; // Reusing existing MULTIPLE sheet export
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\DocenteHistorialExport;
use App\Exports\InstitucionConsolidadoExport;
use App\Exports\ReporteMensualExport;

class ReporteController extends Controller
{
    /**
     * Reporte 1: Asistencias por Período
     * Ahora retornamos el reporte COMPLETO (3 hojas) como antes.
     */
    public function periodo(Request $request)
    {
        $filters = [
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'institucion_id' => $request->input('institucion_id'),
            'user' => $request->user(),
        ];

        $filename = 'Reporte_Completo_' . now()->format('Ymd_His') . '.xlsx';

        try {
            return Excel::download(new AsistenciasMultipleExport($filters), $filename);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error exportando Periodo: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reporte 2: Historial Completo de Docente
     */
    public function docenteHistorial(Request $request)
    {
        $filters = [
            'usuario_id' => $request->input('usuario_id'),
            'fecha_inicio' => $request->input('fecha_inicio'),
        ];

        $filename = 'Historial_Docente_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new DocenteHistorialExport($filters), $filename);
    }

    /**
     * Reporte 3: Consolidado por Institución
     */
    public function institucionConsolidado(Request $request)
    {
        $filters = [
            'institucion_id' => $request->input('institucion_id'),
        ];

        $filename = 'Consolidado_Institucion_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new InstitucionConsolidadoExport($filters), $filename);
    }

    /**
     * Reporte 4: Reporte Mensual
     */
    public function mensual(Request $request)
    {
        $filters = [
            'mes' => $request->input('mes'), // YYYY-MM
            'institucion_id' => $request->input('institucion_id'),
        ];

        $filename = 'Reporte_Mensual_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new ReporteMensualExport($filters), $filename);
    }
}
