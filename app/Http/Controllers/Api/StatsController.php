<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\UsuarioApp;
use App\Models\Institucion;
use App\Models\Asistencia;
use App\Models\Feriado;
use App\Models\HorarioInstitucion;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today();
        $dia = $today->day;
        $mes = $today->month;

        $isAdmin = $user->rol === 'admin';

        /* ============================================================
           📌 INSTITUCIONES DEL ÁMBITO
        ============================================================ */
        $institucionIds = $isAdmin
            ? Institucion::pluck('id')
            : $user->instituciones()->pluck('instituciones.id');

        /* ============================================================
           📌 CONTADORES GENERALES
        ============================================================ */
        $docentesCount = $isAdmin
            ? UsuarioApp::count()
            : UsuarioApp::whereHas('instituciones', fn($q) =>
                $q->whereIn('instituciones.id', $institucionIds)
            )->count();

        $instCount = $institucionIds->count();

        /* ============================================================
           📌 FERIADOS (SOLO INFORMACIÓN, NO AFECTA ASISTENCIAS)
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
           📌 VALIDACIÓN DE HORARIO (solo director)
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
           📌 LÓGICA REAL DE ASISTENCIAS Y FALTAS HOY
        ============================================================ */

        // Obtener registros de hoy
        $registrosHoy = Asistencia::whereIn('institucion_id', $institucionIds)
            ->whereDate('fecha_hora', $today)
            ->get()
            ->groupBy('usuario_id');

        $asistenciasHoy = 0;
        $faltasHoy = 0;

        // Docentes vinculados a estas instituciones
        $docentesDelAmbito = UsuarioApp::whereHas('instituciones', fn($q) =>
            $q->whereIn('instituciones.id', $institucionIds)
        )->pluck('id');

        foreach ($docentesDelAmbito as $docenteId) {

            $registros = $registrosHoy->get($docenteId, collect());

            $entrada = $registros->firstWhere('tipo', 'entrada');
            $salida  = $registros->firstWhere('tipo', 'salida');

            // Asistencia válida: entrada y salida
            if ($entrada && $salida) {
                $asistenciasHoy++;
            } else {
                $faltasHoy++;
            }
        }

        /* ============================================================
           📌 RESPUESTA FINAL
        ============================================================ */

        return response()->json([
            'docentes_count'        => $docentesCount,
            'total_instituciones'   => $instCount,
            'asistencias_hoy'       => $asistenciasHoy,
            'ausencias_hoy'         => $faltasHoy,
            'feriados_nacionales'   => Feriado::where('tipo', 'nacional')->count(),
            'feriados_institucionales' => Feriado::where('tipo', 'institucional')->count(),
            'hoy_no_laborable'      => $hoyNoLaborable,
            'motivo_no_laborable'   => $motivoNoLaborable,
        ]);
    }
}
