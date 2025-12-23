<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AsistenciaMaterializationService
{
    /**
     * Materializa faltas masivamente para la fecha dada usando SQL set-based.
     * Retorna la cantidad de registros insertados.
     */
    public function materializarFaltas(?string $fechaInput = null): int
    {
        $fechaObj = $fechaInput ? Carbon::parse($fechaInput) : Carbon::now();
        $fecha = $fechaObj->format('Y-m-d');

        // Mapeo de días
        $diaSemanaIngles = strtolower($fechaObj->englishDayOfWeek);
        $mapaDias = [
            'monday' => 'L',
            'tuesday' => 'M',
            'wednesday' => 'X',
            'thursday' => 'J',
            'friday' => 'V',
            'saturday' => 'S',
            'sunday' => 'D',
        ];

        $diaLetra = $mapaDias[$diaSemanaIngles] ?? null;

        if (!$diaLetra) {
            \Log::warning("Materialización abortada: Día inválido '{$diaSemanaIngles}' para fecha {$fecha}");
            return 0;
        }

        // SQL Set-Based Logic
        // Insertamos en asistencias seleccionando de usuario_app_institucion (uai)
        // Validamos día de semana con LIKE '%"L"%' (simple, compatible con el formato JSON ["L","M"...])
        $sql = "
            INSERT INTO asistencias (
                usuario_app_id, 
                institucion_id, 
                horario_institucion_id,
                fecha, 
                estado_diario, 
                observacion,
                created_at, 
                updated_at
            )
            SELECT 
                uai.usuario_app_id,
                uai.institucion_id,
                uai.horario_institucion_id,
                ?,
                'FALTA',
                'Falta registrada automáticamente - No marcó asistencia',
                NOW(),
                NOW()
            FROM usuario_app_institucion uai
            INNER JOIN horarios_institucion hi ON hi.id = uai.horario_institucion_id
            -- Left Join para verificar inexistencia (evitar duplicados y respetar idempotencia)
            LEFT JOIN asistencias a ON a.usuario_app_id = uai.usuario_app_id
                AND a.institucion_id = uai.institucion_id
                AND a.fecha = ?
            WHERE 
                uai.estado = 'ACTIVO'
                AND uai.deleted_at IS NULL
                AND hi.activo = 1
                -- Validar vigencia de asignación
                AND uai.fecha_inicio <= ?
                AND (uai.fecha_fin IS NULL OR uai.fecha_fin >= ?)
                -- Que no exista registro previo
                AND a.id IS NULL
                -- Validar día de semana (formato JSON [\"L\", ...])
                AND hi.dias_semana LIKE ?
        ";

        $bindings = [
            $fecha, // :fecha1 (SELECT)
            $fecha, // :fecha2 (JOIN)
            $fecha, // :fecha3 (WHERE start)
            $fecha, // :fecha4 (WHERE end)
            '%"' . $diaLetra . '"%', // :dia_like
        ];

        // Ejecutar sentencia y obtener filas afectadas
        $affected = DB::affectingStatement($sql, $bindings);

        \Log::info("Materialización de faltas completada para {$fecha}. Registros creados: {$affected}");

        return $affected;
    }
}
