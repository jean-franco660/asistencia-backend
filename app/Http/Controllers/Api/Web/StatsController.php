<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\UsuarioApp;
use App\Models\Institucion;
use App\Models\Asistencia;
use App\Models\Feriado;
use App\Models\HorarioInstitucion;
use App\Models\UsuarioWeb;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today();
        $dia = $today->day;
        $mes = $today->month;

        $isAdmin = $user->esAdminOSuperAdmin();
        /* ============================================================
            INSTITUCIONES DEL ÁMBITO
        ============================================================ */
        $institucionIds = $isAdmin
            ? Institucion::pluck('id')
            : $user->instituciones()->pluck('instituciones.id');

        /* ============================================================
            CONTADORES GENERALES
        ============================================================ */
        $docentesCount = $isAdmin
            ? UsuarioApp::count()
            : UsuarioApp::whereHas(
                'instituciones',
                fn($q) =>
                $q->whereIn('instituciones.id', $institucionIds)
            )->count();

        $instCount = $institucionIds->count();

        /* ============================================================
            FERIADOS (SOLO INFORMACIÓN, NO AFECTA ASISTENCIAS)
        ============================================================ */

        // Feriado nacional
        $feriadoNac = Feriado::where('tipo', 'nacional')
            ->where('dia', $dia)
            ->where('mes', $mes)
            ->first();

        // Feriado institucional
        $feriadoInst = Feriado::where('tipo', 'institucional')
            ->whereIn('institucion_id', $institucionIds)
            ->where('dia', $dia)
            ->where('mes', $mes)
            ->where('activo', true)
            ->first();

        // Prioridad: institucional > nacional
        $feriado = $feriadoInst ?? $feriadoNac;

        $hoyNoLaborable = !!$feriado;
        $motivoNoLaborable = optional($feriado)->descripcion;

        /* ============================================================
            VALIDACIÓN DE HORARIO (solo supervisor)
        ============================================================ */

        if (!$hoyNoLaborable && !$isAdmin) {

            $map = [
                'Monday' => 'L',
                'Tuesday' => 'M',
                'Wednesday' => 'X',
                'Thursday' => 'J',
                'Friday' => 'V',
                'Saturday' => 'S',
                'Sunday' => 'D'
            ];
            $diaLetra = $map[$today->format('l')];

            // Verificar si hay horario activo para este día
            $hayHorario = HorarioInstitucion::whereIn('institucion_id', $institucionIds)
                ->where('activo', true)
                ->whereRaw("JSON_CONTAINS(dias_semana, '\"$diaLetra\"')")
                ->exists();

            if (!$hayHorario) {
                $hoyNoLaborable = true;
                $motivoNoLaborable = 'Día no laborable por horario';
            }
        }

        /* ============================================================
            LÓGICA REAL DE ASISTENCIAS Y FALTAS HOY
        ============================================================ */

        // Obtener registros de hoy (Headers)
        $registrosHoy = Asistencia::whereIn('institucion_id', $institucionIds)
            ->whereDate('fecha', $today)
            ->get()
            ->groupBy('usuario_app_id');

        $asistenciasHoy = 0;
        $faltasHoy = 0;

        // Docentes vinculados a estas instituciones
        $docentesDelAmbito = UsuarioApp::whereHas(
            'instituciones',
            fn($q) =>
            $q->whereIn('instituciones.id', $institucionIds)
        )->pluck('id');

        foreach ($docentesDelAmbito as $docenteId) {
            $registro = $registrosHoy->get($docenteId, collect())->first();

            // Si existe registro y estado_diario es PRESENTE o TARDANZA
            if ($registro && in_array($registro->estado_diario, ['PRESENTE', 'TARDANZA'])) {
                $asistenciasHoy++;
            } else {
                $faltasHoy++;
            }
        }

        /* ============================================================
            ESTADÍSTICAS ADICIONALES
        ============================================================ */

        // Instituciones activas (con al menos un docente o actividad reciente)
        $institucionesActivas = Institucion::whereHas('asignacionesActivas')
            ->whereIn('id', $institucionIds)
            ->count();

        // Docentes activos (que han marcado asistencia en los últimos 30 días)
        $docentesActivos = UsuarioApp::whereHas('asistencias', function ($q) {
            $q->where('fecha', '>=', Carbon::now()->subDays(30));
        })
            ->whereHas('instituciones', fn($q) => $q->whereIn('instituciones.id', $institucionIds))
            ->count();

        // Asistencias del mes actual (Headers con estado PRESENTE o TARDANZA)
        $asistenciasMesActual = Asistencia::whereIn('institucion_id', $institucionIds)
            ->whereYear('fecha', $today->year)
            ->whereMonth('fecha', $today->month)
            ->whereIn('estado_diario', ['PRESENTE', 'TARDANZA'])
            ->count();

        // Promedio de asistencia (basado en últimos 30 días)
        $diasLaborables = 22; // Promedio de días laborables por mes
        $docentesTotal = max($docentesCount, 1); // Evitar división por cero
        $promedioAsistencia = $asistenciasMesActual > 0
            ? round(($asistenciasMesActual / ($docentesTotal * $diasLaborables)) * 100, 1)
            : 0;

        // Estadísticas de justificaciones
        $justificacionesPendientes = \App\Models\Justificacion::where('estado', 'PENDIENTE')
            ->whereIn('institucion_id', $institucionIds)
            ->count();

        $justificacionesAprobadas = \App\Models\Justificacion::where('estado', 'APROBADO')
            ->whereIn('institucion_id', $institucionIds)
            ->count();

        $justificacionesRechazadas = \App\Models\Justificacion::where('estado', 'RECHAZADO')
            ->whereIn('institucion_id', $institucionIds)
            ->count();

        /* ============================================================
            RESPUESTA FINAL (ESTRUCTURA EXPANDIDA)
        ============================================================ */

        return response()->json([
            //  NUEVA ESTRUCTURA ANIDADA
            'instituciones' => [
                'total' => $instCount,
                'activas' => $institucionesActivas,
            ],
            'docentes' => [
                'total' => $docentesCount,
                'activos' => $docentesActivos,
                'docentes_count' => $docentesCount, // Alias para compatibilidad
            ],
            'asistencias' => [
                'hoy' => $asistenciasHoy,
                'mes_actual' => $asistenciasMesActual,
                'promedio_asistencia' => $promedioAsistencia,
                'asistencias_hoy' => $asistenciasHoy, // Alias para compatibilidad
            ],
            'justificaciones' => [
                'pendientes' => $justificacionesPendientes,
                'aprobadas' => $justificacionesAprobadas,
                'rechazadas' => $justificacionesRechazadas,
            ],

            //  CAMPOS LEGACY (RETROCOMPATIBILIDAD)
            'docentes_count' => $docentesCount,
            'total_instituciones' => $instCount,
            'asistencias_hoy' => $asistenciasHoy,
            'ausencias_hoy' => $faltasHoy,
            'feriados_nacionales' => Feriado::where('tipo', 'nacional')->count(),
            'feriados_institucionales' => Feriado::where('tipo', 'institucional')->count(),
            'hoy_no_laborable' => $hoyNoLaborable,
            'motivo_no_laborable' => $motivoNoLaborable,
        ]);
    }
}
