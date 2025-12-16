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
            'procesados' => 0,
            'creados' => 0,
            'actualizados' => 0,
            'errores' => 0,
            'errores_detalle' => [],
        ];

        foreach ($rows as $index => $row) {
            $numeroFila = $offset + $index + 2;

            $rowArray = is_array($row) ? $row : $row->toArray();

            try {
                $accion = $this->procesarFilaInstitucion($rowArray);

                $resultados['procesados']++;

                if ($accion === 'creado') {
                    $resultados['creados']++;
                } else {
                    $resultados['actualizados']++;
                }
            } catch (Exception $e) {
                $resultados['errores']++;

                $resultados['errores_detalle'][] = [
                    'fila' => $numeroFila,
                    'codigo_modular_ie' => $rowArray['codigo_modular_ie'] ?? ($rowArray['codigo'] ?? null),
                    'institucion' => $rowArray['nombre'] ?? null,
                    'distrito' => $rowArray['distrito'] ?? null,
                    'motivo' => $e->getMessage(),
                    // Si no quieres guardar toda la fila por tamaño, elimina esto:
                    // 'fila_original' => $rowArray,
                ];

                Log::warning("Error al importar institución (chunk)", [
                    'fila' => $numeroFila,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($importLog) {
            $this->actualizarProgreso($importLog, $resultados);
        }

        return $resultados;
    }

    protected function procesarFilaInstitucion(array $row): string
    {
        // Headings esperados:
        // codigo_modular_ie, nombre, direccion, distrito, nivel_educativo, centro_poblado, latitud, longitud, radio

        $codigo = $row['codigo_modular_ie'] ?? null;
        $nombre = $row['nombre'] ?? null;
        $distrito = $row['distrito'] ?? null;
        $nivelEducativo = $row['nivel_educativo'] ?? null;

        if (empty($codigo)) {
            throw new Exception('Falta campo obligatorio: codigo_modular_ie');
        }
        if (empty($nombre)) {
            throw new Exception('Falta campo obligatorio: nombre');
        }
        if (empty($distrito)) {
            throw new Exception('Falta campo obligatorio: distrito');
        }

        $codigo = strtoupper(trim((string) $codigo));
        $nombre = trim((string) $nombre);
        $distrito = trim((string) $distrito);

        if ($this->validarCodigoSoloNumerico7) {
            if (!preg_match('/^\d{7}$/', $codigo)) {
                throw new Exception("Código modular IE inválido: debe ser 7 dígitos numéricos");
            }
        }

        $direccion = $row['direccion'] ?? null;
        $centroPoblado = $row['centro_poblado'] ?? null;

        $latitud = $row['latitud'] ?? null;
        $longitud = $row['longitud'] ?? null;
        $radio = $row['radio'] ?? null;

        return DB::transaction(function () use ($codigo, $nombre, $nivelEducativo, $distrito, $centroPoblado, $direccion, $latitud, $longitud, $radio) {
            $institucion = Institucion::where('codigo_modular_ie', $codigo)->first();
            $accion = $institucion ? 'actualizado' : 'creado';

            if (!$institucion) {
                $institucion = new Institucion();
                $institucion->codigo_modular_ie = $codigo;
            }

            $institucion->nombre = $nombre;
            $institucion->nivel_educativo = $nivelEducativo ? trim((string) $nivelEducativo) : null;
            $institucion->distrito = $distrito;
            $institucion->centro_poblado = $centroPoblado ? trim((string) $centroPoblado) : null;
            $institucion->direccion = $direccion ? trim((string) $direccion) : null;

            // Latitud
            if ($latitud !== null && $latitud !== '') {
                if (!is_numeric($latitud))
                    throw new Exception("Latitud inválida (no numérica)");
                $lat = (float) $latitud;
                if ($lat < -90 || $lat > 90)
                    throw new Exception("Latitud fuera de rango (-90..90)");
                $institucion->latitud = $lat;
            } else {
                $institucion->latitud = null;
            }

            // Longitud
            if ($longitud !== null && $longitud !== '') {
                if (!is_numeric($longitud))
                    throw new Exception("Longitud inválida (no numérica)");
                $lng = (float) $longitud;
                if ($lng < -180 || $lng > 180)
                    throw new Exception("Longitud fuera de rango (-180..180)");
                $institucion->longitud = $lng;
            } else {
                $institucion->longitud = null;
            }

            // Radio
            if ($radio !== null && $radio !== '') {
                if (!is_numeric($radio))
                    throw new Exception("Radio inválido (no numérico)");
                $r = (int) $radio;
                if ($r <= 0 || $r > 5000)
                    throw new Exception("Radio fuera de rango razonable (1..5000)");
                $institucion->radio = $r;
            } elseif (!$institucion->exists) {
                $institucion->radio = 30;
            }

            $institucion->save();

            Log::info("Institución importada", [
                'codigo_modular_ie' => $institucion->codigo_modular_ie,
                'accion' => $accion,
            ]);

            return $accion;
        });
    }

    protected function actualizarProgreso(ImportacionLog $importLog, array $resultados): void
    {
        $procesados = (int) ($resultados['procesados'] ?? 0);
        $errores = (int) ($resultados['errores'] ?? 0);

        $importLog->increment('procesados', $procesados);
        $importLog->increment('errores_count', $errores);

        $exitos = max(0, $procesados - $errores);
        $importLog->increment('exitosos', $exitos);

        if (!empty($resultados['errores_detalle'])) {
            $actual = $importLog->errores_detalle ?? [];
            $actual = is_array($actual) ? $actual : [];

            $importLog->update([
                'errores_detalle' => array_merge($actual, $resultados['errores_detalle']),
            ]);
        }
    }
}
