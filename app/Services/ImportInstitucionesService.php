<?php

namespace App\Services;

use App\Models\Institucion;
use App\Models\ImportacionLog;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportInstitucionesService
{
    /**
     * UGEL utiliza códigos alfanuméricos (ej: P210002) además de numéricos de 7 dígitos.
     * Cambiado a false para aceptar ambos formatos.
     */
    private bool $validarCodigoSoloNumerico7 = false;

    public function procesarChunk(Collection $rows, ?ImportacionLog $importLog = null, int $offset = 0): array
    {
        $resultados = [
            'procesados_chunk' => 0,
            'exitosos_chunk' => 0,
            'errores_chunk' => 0,
            'creados_chunk' => 0,
            'actualizados_chunk' => 0,
            'errores_detalle' => [],
        ];

        // 1) Normalizar/validar filas (sin DB)
        $filas = [];
        $codigos = [];

        foreach ($rows as $index => $row) {
            $numeroFila = $offset + $index + 2;
            $rowArray = is_array($row) ? $row : $row->toArray();

            try {
                $norm = $this->normalizarFilaInstitucion($rowArray);

                $filas[] = [
                    'fila_excel' => $numeroFila,
                    'raw' => $rowArray,
                    'norm' => $norm,
                ];

                $codigos[] = $norm['codigo_modular_ie'];
                $resultados['procesados_chunk']++;

            } catch (Exception $e) {
                $resultados['errores_chunk']++;
                $resultados['errores_detalle'][] = [
                    'fila' => $numeroFila,
                    'codigo_modular_ie' => $rowArray['codigo_modular_ie'] ?? ($rowArray['codigo'] ?? null),
                    'institucion' => $rowArray['nombre'] ?? null,
                    'distrito' => $rowArray['distrito'] ?? null,
                    'motivo' => $e->getMessage(),
                ];

                Log::warning('Error al importar institución (chunk)', [
                    'fila' => $numeroFila,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($filas)) {
            if ($importLog) {
                $this->actualizarProgresoChunkRapido($importLog, $resultados);
            }
            return $resultados;
        }

        // 2) Precargar instituciones existentes (1 query)
        $codigos = array_values(array_unique(array_filter($codigos)));

        $existentes = Institucion::query()
            ->whereIn('codigo_modular_ie', $codigos)
            ->get(['id', 'codigo_modular_ie'])
            ->keyBy('codigo_modular_ie');

        // 3) Preparar batch upsert
        $batch = [];
        $now = now();

        foreach ($filas as $item) {
            $norm = $item['norm'];
            $codigo = $norm['codigo_modular_ie'];

            $existe = $existentes->has($codigo);
            if ($existe) {
                $resultados['actualizados_chunk']++;
            } else {
                $resultados['creados_chunk']++;
            }

            $batch[] = [
                'codigo_modular_ie' => $codigo,
                'nombre' => $norm['nombre'],
                'distrito' => $norm['distrito'],
                'nivel_educativo' => $norm['nivel_educativo'],
                'tipo_gestion' => $norm['tipo_gestion'],
                'latitud' => $norm['latitud'],
                'longitud' => $norm['longitud'],
                'radio' => $norm['radio'],
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        // 4) Persistir en lote (1 transacción por chunk)
        DB::transaction(function () use ($batch) {
            Institucion::upsert(
                $batch,
                ['codigo_modular_ie'],
                [
                    'nombre',
                    'distrito',
                    'nivel_educativo',
                    'tipo_gestion',
                    'latitud',
                    'longitud',
                    'radio',
                    'updated_at',
                ]
            );
        });

        $resultados['exitosos_chunk'] = max(
            0,
            (int) $resultados['procesados_chunk'] - (int) $resultados['errores_chunk']
        );

        if ($importLog) {
            $this->actualizarProgresoChunkRapido($importLog, $resultados);
        }

        return $resultados;
    }

    /**
     * Normaliza/valida una fila sin DB. Devuelve array listo para upsert.
     */
    private function normalizarFilaInstitucion(array $row): array
    {
        // Campos obligatorios
        $codigo = $row['codigo_modular_ie'] ?? ($row['codigo'] ?? null);
        $nombre = $row['nombre'] ?? null;
        $distrito = $row['distrito'] ?? null;
        $nivelEducativo = $row['nivel_educativo'] ?? null;

        if (empty($codigo))
            throw new Exception('Falta campo obligatorio: codigo_modular_ie');
        if (empty($nombre))
            throw new Exception('Falta campo obligatorio: nombre');
        if (empty($distrito))
            throw new Exception('Falta campo obligatorio: distrito');
        if (empty($nivelEducativo))
            throw new Exception('Falta campo obligatorio: nivel_educativo');

        $codigo = strtoupper(trim((string) $codigo));
        $nombre = trim((string) $nombre);
        $distrito = trim((string) $distrito);
        $nivelEducativo = trim((string) $nivelEducativo);

        if ($this->validarCodigoSoloNumerico7) {
            if (!preg_match('/^\d{7}$/', $codigo)) {
                throw new Exception('Código modular IE inválido: debe ser 7 dígitos numéricos');
            }
        }

        // Campos opcionales
        $tipoGestion = isset($row['tipo_gestion']) && $row['tipo_gestion'] !== '' ? trim((string) $row['tipo_gestion']) : null;

        // Validar enum tipo_gestion
        if ($tipoGestion !== null) {
            $tiposValidos = ['PUBLICA', 'PRIVADA', 'PUBLICA_CONVENIO'];
            $tipoGestion = strtoupper($tipoGestion);
            if (!in_array($tipoGestion, $tiposValidos, true)) {
                throw new Exception('tipo_gestion inválido. Valores permitidos: PUBLICA, PRIVADA, PUBLICA_CONVENIO');
            }
        }

        // Latitud
        $latitud = null;
        if (array_key_exists('latitud', $row) && $row['latitud'] !== null && $row['latitud'] !== '') {
            if (!is_numeric($row['latitud']))
                throw new Exception('Latitud inválida (no numérica)');
            $lat = (float) $row['latitud'];
            if ($lat < -90 || $lat > 90)
                throw new Exception('Latitud fuera de rango (-90..90)');
            $latitud = $lat;
        }

        // Longitud
        $longitud = null;
        if (array_key_exists('longitud', $row) && $row['longitud'] !== null && $row['longitud'] !== '') {
            if (!is_numeric($row['longitud']))
                throw new Exception('Longitud inválida (no numérica)');
            $lng = (float) $row['longitud'];
            if ($lng < -180 || $lng > 180)
                throw new Exception('Longitud fuera de rango (-180..180)');
            $longitud = $lng;
        }

        // Radio
        $radio = null;
        if (array_key_exists('radio', $row) && $row['radio'] !== null && $row['radio'] !== '') {
            if (!is_numeric($row['radio']))
                throw new Exception('Radio inválido (no numérico)');
            $r = (int) $row['radio'];
            if ($r <= 0 || $r > 5000)
                throw new Exception('Radio fuera de rango razonable (1..5000)');
            $radio = $r;
        } else {
            $radio = 30;
        }

        return [
            'codigo_modular_ie' => $codigo,
            'nombre' => $nombre,
            'distrito' => $distrito,
            'nivel_educativo' => $nivelEducativo,
            'tipo_gestion' => $tipoGestion,
            'latitud' => $latitud,
            'longitud' => $longitud,
            'radio' => $radio,
        ];
    }

    /**
     * Actualiza contadores y errores una sola vez por chunk.
     */
    private function actualizarProgresoChunkRapido(ImportacionLog $importLog, array $resultados): void
    {
        $procesados = (int) ($resultados['procesados_chunk'] ?? 0);
        $exitosos = (int) ($resultados['exitosos_chunk'] ?? 0);
        $errores = (int) ($resultados['errores_chunk'] ?? 0);

        ImportacionLog::whereKey($importLog->id)->update([
            'procesados' => DB::raw("procesados + {$procesados}"),
            'exitosos' => DB::raw("exitosos + {$exitosos}"),
            'errores_count' => DB::raw("errores_count + {$errores}"),
        ]);

        if (!empty($resultados['errores_detalle'])) {
            $importLog->refresh();
            $actual = $importLog->errores_detalle ?? [];
            $actual = is_array($actual) ? $actual : [];

            $importLog->update([
                'errores_detalle' => array_merge($actual, $resultados['errores_detalle']),
            ]);
        }
    }
}
