<?php

namespace App\Services;

use App\Models\Asistencia;
use App\Models\AsistenciaDiaria;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Servicio para calcular el estado diario de asistencia
 * basándose en las marcaciones individuales validadas.
 * 
 * Aplica las reglas de negocio según especificación:
 * - Una marcación "cuenta" si es VALIDA o (OBSERVADA + APROBADA)
 * - FALTA si falta ENTRADA o SALIDA que cuente
 * - TARDANZA si entrada cuenta ocurre después del límite
 * - PRESENTE si entrada cuenta está dentro de ventana
 */
class DailyStateCalculatorService
{
    /**
     * Calcula el estado diario de una asistencia basándose en sus marcaciones.
     * 
     * @param Asistencia $asistencia
     * @return array ['estado_diario', 'es_falta', 'hora_entrada', 'hora_salida', 'minutos_tardanza', 'observacion']
     */
    public function calculate(Asistencia $asistencia): array
    {
        // 1. Obtener marcaciones que CUENTAN
        $entradas_validas = $this->getValidMarkings($asistencia, AsistenciaDiaria::TIPO_ENTRADA);
        $salidas_validas = $this->getValidMarkings($asistencia, AsistenciaDiaria::TIPO_SALIDA);

        // 2. Si falta ENTRADA o SALIDA → FALTA
        //  CORRECCIÓN 5: Guardar horas parciales para trazabilidad
        if ($entradas_validas->isEmpty() || $salidas_validas->isEmpty()) {
            return [
                'estado_diario' => 'FALTA',
                'es_falta' => true,
                'hora_entrada' => $entradas_validas->isNotEmpty()
                    ? $entradas_validas->sortBy('marcada_en')->first()->marcada_en->format('H:i:s')
                    : null,
                'hora_salida' => $salidas_validas->isNotEmpty()
                    ? $salidas_validas->sortBy('marcada_en')->last()->marcada_en->format('H:i:s')
                    : null,
                'minutos_tardanza' => null,
                'observacion' => $entradas_validas->isEmpty()
                    ? 'Falta entrada'
                    : 'Falta salida'
            ];
        }

        // 3. Tomar primera ENTRADA y última SALIDA que cuentan
        $primera_entrada = $entradas_validas->sortBy('marcada_en')->first();
        $ultima_salida = $salidas_validas->sortBy('marcada_en')->last();

        // 4. Calcular tardanza
        $horario = $asistencia->horario;

        if (!$horario) {
            // Sin horario asignado, consideramos PRESENTE por defecto
            return [
                'estado_diario' => 'PRESENTE',
                'es_falta' => false,
                'hora_entrada' => $primera_entrada->marcada_en->format('H:i:s'),
                'hora_salida' => $ultima_salida->marcada_en->format('H:i:s'),
                'minutos_tardanza' => null,
                'observacion' => 'Sin horario asignado'
            ];
        }

        $limite_entrada = Carbon::parse($horario->hora_entrada)
            ->addMinutes($horario->tolerancia_entrada_minutos);

        //  CORRECCIÓN 2: Orden correcto de diffInMinutes
        $minutos_tarde = $primera_entrada->marcada_en->gt($limite_entrada)
            ? $limite_entrada->diffInMinutes($primera_entrada->marcada_en)
            : 0;

        // 5. Determinar estado
        $estado = $minutos_tarde > 0 ? 'TARDANZA' : 'PRESENTE';

        return [
            'estado_diario' => $estado,
            'es_falta' => false,
            'hora_entrada' => $primera_entrada->marcada_en->format('H:i:s'),
            'hora_salida' => $ultima_salida->marcada_en->format('H:i:s'),
            'minutos_tardanza' => $minutos_tarde > 0 ? $minutos_tarde : null
        ];
    }

    /**
     * Recalcula y actualiza directamente el modelo Asistencia.
     * 
     * @param Asistencia $asistencia
     * @return void
     */
    public function recalculate(Asistencia $asistencia): void
    {
        $resultado = $this->calculate($asistencia);
        $asistencia->update($resultado);
    }

    /**
     * Obtiene las marcaciones que "cuentan" para el cálculo diario.
     * 
     * Una marcación cuenta si:
     * - estado_marcacion = VALIDA, o
     * - estado_marcacion = OBSERVADA y estado_revision = APROBADA
     * Y además:
     * - No es ANULADA
     * - No está soft-deleted
     * 
     * @param Asistencia $asistencia
     * @param string $tipo 'ENTRADA' o 'SALIDA'
     * @return Collection
     */
    private function getValidMarkings(Asistencia $asistencia, string $tipo): Collection
    {
        return $asistencia->marcaciones()
            ->where('tipo', $tipo)
            ->where(function ($q) {
                $q->where('estado_marcacion', AsistenciaDiaria::ESTADO_VALIDA)
                    ->orWhere(function ($q2) {
                        $q2->where('estado_marcacion', AsistenciaDiaria::ESTADO_OBSERVADA)
                            ->where('estado_revision', AsistenciaDiaria::REVISION_APROBADA);
                    });
            })
            ->where('estado_marcacion', '!=', AsistenciaDiaria::ESTADO_ANULADA)
            ->whereNull('deleted_at')
            ->get();
    }
}
