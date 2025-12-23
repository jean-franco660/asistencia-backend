<?php

namespace App\Services;

use App\Models\AsistenciaDiaria;
use App\Models\HorarioInstitucion;
use Carbon\Carbon;

class AttendanceValidationService
{
    /**
     * Validates a new marking against the schedule and business rules.
     * Returns an array with ['estado', 'motivo'].
     */
    public function validateMarking(string $tipo, Carbon $marcadaEn, ?HorarioInstitucion $horario, bool $dentroRango): array
    {
        // 1. Validar Geofence (Prioridad alta o baja? User says "Toda marcación anómala => OBSERVADA")
        // Si está fuera de rango, YA es observada por ese motivo.
        // Pero podría TAMBIÉN estar fuera de horario.
        // Estandarizamos: Si fuera de rango => FUERA_DE_RANGO (y tal vez meta info de horario)

        if (!$dentroRango) {
            return [
                'estado' => AsistenciaDiaria::ESTADO_OBSERVADA,
                'motivo' => AsistenciaDiaria::MOTIVO_FUERA_DE_RANGO
            ];
        }

        if (!$horario) {
            // Sin horario asignado => Observada
            return [
                'estado' => AsistenciaDiaria::ESTADO_OBSERVADA,
                'motivo' => 'SIN_HORARIO_ASIGNADO'
            ];
        }

        // 2. Validar Ventana Horaria
        // Convertir marcadaEn a hora del día (H:i:s) para comparar con Time objects del horario
        // OJO: Manejar cruce de medianoche si fuera necesario, pero por ahora comparamos horas simples.
        // Usamos la fecha de la marcación combinada con la hora del horario para crear datetimes comparables.

        $fechaBase = $marcadaEn->format('Y-m-d');

        if ($tipo === 'ENTRADA') {
            $horaEntrada = Carbon::parse("$fechaBase {$horario->hora_entrada}");
            $limiteFin = $horaEntrada->copy()->addMinutes($horario->tolerancia_entrada_minutos);

            // Regla: hora_entrada <= marcada_en <= hora_entrada + tol
            // ¿Qué pasa si llega ANTES? "hora_entrada <= marcada_en". O sea NO se permite entrada temprana.
            // Si llega 5 min antes, es INVALIDA? User dice: "hora_entrada <= ..."
            // Asumiremos estricto por petición del user. 

            $isEarly = $marcadaEn->lt($horaEntrada);
            // $isLate = $marcadaEn->gt($limiteFin); // RELAJACION: Tarde es VÁLIDO (con tardanza), no OBSERVADO.

            if ($isEarly) {
                return [
                    'estado' => AsistenciaDiaria::ESTADO_OBSERVADA,
                    'motivo' => AsistenciaDiaria::MOTIVO_FUERA_DE_HORARIO
                ];
            }

            // Si es tarde pero validamos (puerta abierta), es VALIDA.
            // AsistenciaService::calcularEstado se encargará de poner "Tardanza" y los minutos.

        } elseif ($tipo === 'SALIDA') {
            $horaSalida = Carbon::parse("$fechaBase {$horario->hora_salida}");
            $limiteInicio = $horaSalida->copy()->subMinutes($horario->tolerancia_salida_minutos);

            // Regla: hora_salida - tol <= marcada_en <= hora_salida

            $isEarly = $marcadaEn->lt($limiteInicio);
            $isLate = $marcadaEn->gt($horaSalida); // Salida tardía también es observada según fórmula estricta?
            // "marcada_en <= hora_salida". Si sale después, es OBSERVADA.

            if ($isEarly || $isLate) {
                return [
                    'estado' => AsistenciaDiaria::ESTADO_OBSERVADA,
                    'motivo' => AsistenciaDiaria::MOTIVO_FUERA_DE_HORARIO
                ];
            }
        }

        // Si pasa todas las reglas
        return [
            'estado' => AsistenciaDiaria::ESTADO_VALIDA,
            'motivo' => AsistenciaDiaria::MOTIVO_OK
        ];
    }

    /**
     * Determina el estado del día (PRESENTE, FALTA, OBS) basado en las marcaciones.
     * Fase 5.
     */
    public function determineDailyState($asistencia)
    {
        // TODO: Implementar en Fase 5
    }
}
