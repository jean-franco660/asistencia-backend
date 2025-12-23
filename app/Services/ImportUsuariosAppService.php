<?php

namespace App\Services;

use App\Imports\UsuariosAppImport;
use App\Models\ImportacionLog;
use App\Models\Institucion;
use App\Models\UsuarioApp;
use App\Models\UsuarioAppInstitucion;
use DomainException;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;

class ImportUsuariosAppService
{
    /**
     * Validación y disparo de importación (si lo usas desde controller con UploadedFile).
     */
    public function procesarArchivo(UploadedFile $archivo, ImportacionLog $importLog): array
    {
        $validator = Validator::make(['archivo' => $archivo], [
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        if ($validator->fails()) {
            throw new DomainException($validator->errors()->first());
        }

        $importLog->update([
            'archivo_original' => $archivo->getClientOriginalName() ?? $importLog->archivo_original,
        ]);

        Excel::import(new UsuariosAppImport($importLog, $this), $archivo);

        return [
            'message' => 'Importación ejecutada.',
            'import_log_id' => $importLog->id,
        ];
    }

    /**
     * Método pensado para Jobs (recibe path relativo en storage).
     */
    public function procesarConProgreso(string $archivoPath, ImportacionLog $importLog): array
    {
        $absolutePath = Storage::path($archivoPath);

        if (!is_file($absolutePath)) {
            throw new Exception("Archivo no encontrado en storage: {$archivoPath}");
        }

        if ($importLog->estado !== ImportacionLog::ESTADO_PROCESSING) {
            $importLog->update([
                'estado' => ImportacionLog::ESTADO_PROCESSING,
                'iniciado_en' => now(),
            ]);
        }

        $importLog->update([
            'archivo_temp' => $archivoPath,
        ]);

        Excel::import(new UsuariosAppImport($importLog, $this), $absolutePath);

        return [
            'message' => 'Importación ejecutada por chunks.',
            'import_log_id' => $importLog->id,
        ];
    }

    /**
     * Procesar un chunk (optimizado).
     *
     * Nota: este método NO hace queries por fila. Todo se prepara y se ejecuta en lote.
     */
    public function procesarChunk(Collection $rows, ?ImportacionLog $importLog = null, int $offset = 0): array
    {
        // Resultado extendido (útil para logs del Import)
        $resultados = [
            'procesados_chunk' => 0, // filas leídas válidas (no vacías)
            'exitosos_chunk' => 0, // filas aplicadas sin error
            'errores_chunk' => 0, // filas con error
            'creados_chunk' => 0,
            'actualizados_chunk' => 0,
            'errores_detalle' => [],
        ];

        // 1) Normalizar filas + validar requeridos en memoria
        $filas = [];
        $codigosDoc = [];
        $codigosIE = [];

        foreach ($rows as $index => $row) {
            $rowArray = is_array($row) ? $row : $row->toArray();

            $numeroFila = $offset + $index + 2; // tu convención original

            try {
                $norm = $this->normalizarFila($rowArray);

                $filas[] = [
                    'fila_excel' => $numeroFila,
                    'raw' => $rowArray,
                    'norm' => $norm,
                ];

                $codigosDoc[] = $norm['codigo_doc'];
                $codigosIE[] = $norm['codigo_ie'];

                $resultados['procesados_chunk']++;
            } catch (Exception $e) {
                $resultados['errores_chunk']++;
                $resultados['errores_detalle'][] = $this->armarErrorDetalle($numeroFila, $rowArray, $e->getMessage());
            }
        }

        // Si todas fallaron en validación, solo actualizar log y salir
        if (empty($filas)) {
            if ($importLog) {
                $this->actualizarProgresoChunkRapido($importLog, $resultados);
            }
            return $resultados;
        }

        // 2) Cache instituciones por chunk (1 query)
        $instCache = $this->buildInstitucionesCache($codigosIE);

        // 3) Precargar usuarios por codigo_modular (1 query)
        $codigosDoc = array_values(array_unique($codigosDoc));

        $usuariosExistentes = UsuarioApp::query()
            ->whereIn('codigo_modular', $codigosDoc)
            ->get()
            ->keyBy('codigo_modular');

        // 4) Preparar batch upsert usuarios_app
        $batchUpsert = [];
        $now = now();

        // Cache de hashes (si passwords se repiten)
        $hashCache = [];

        foreach ($filas as $item) {
            $filaExcel = $item['fila_excel'];
            $norm = $item['norm'];

            // institución debe existir
            $inst = $instCache->get($norm['codigo_ie']);

            // 🐛 DEBUG: Log búsqueda de institución
            \Log::debug('Buscando institución para usuario', [
                'codigo_ie' => $norm['codigo_ie'],
                'institucion_encontrada' => $inst ? 'SI' : 'NO',
                'institucion_id' => $inst?->id,
                'codigo_doc' => $norm['codigo_doc'],
            ]);

            if (!$inst) {
                $resultados['errores_chunk']++;
                $resultados['errores_detalle'][] = $this->armarErrorDetalle(
                    $filaExcel,
                    $item['raw'],
                    "Institución con código '{$norm['codigo_ie']}' no encontrada"
                );
                continue;
            }

            $codigoDoc = $norm['codigo_doc'];

            $existe = $usuariosExistentes->has($codigoDoc);

            // Password: si viene en excel, hashear. Si no, generar automáticamente.
            // Patrón automático: primer_nombre + últimos_4_dígitos_dni
            if ($norm['password_raw'] !== '') {
                $plain = $norm['password_raw'];
            } else {
                $plain = $this->generarPasswordAutomatica($norm['nombres'], $norm['dni']);
            }
            $hash = $hashCache[$plain] ??= Hash::make($plain);

            $batchUpsert[] = [
                'codigo_modular' => $codigoDoc,
                'dni' => $norm['dni'],
                'apellido_paterno' => $norm['apellido_paterno'],
                'apellido_materno' => $norm['apellido_materno'],
                'nombres' => $norm['nombres'],
                'sexo' => $norm['sexo'],
                'telefono' => $norm['telefono'],
                'acceso_habilitado' => true,
                'password' => $hash,
                'updated_at' => $now,
                'created_at' => $now,
            ];

            if ($existe) {
                $resultados['actualizados_chunk']++;
            } else {
                $resultados['creados_chunk']++;
            }

            // Guardamos datos para pivot después
            $filasPivot[] = [
                'codigo_doc' => $codigoDoc,
                'institucion_id' => (int) $inst->id,
                'cargo' => $norm['cargo'],
            ];
        }

        // 5) Persistencia: 1 transacción por chunk
        DB::transaction(function () use (&$resultados, $batchUpsert, $codigosDoc, &$usuariosExistentes, $filasPivot, $now) {
            // 5.1 upsert usuarios
            if (!empty($batchUpsert)) {
                UsuarioApp::upsert(
                    $batchUpsert,
                    ['codigo_modular'],
                    ['dni', 'apellido_paterno', 'apellido_materno', 'nombres', 'sexo', 'telefono', 'acceso_habilitado', 'password', 'updated_at']
                );
            }

            // 5.2 Recargar ids de usuarios del chunk (1 query)
            $usuarios = UsuarioApp::query()
                ->whereIn('codigo_modular', $codigosDoc)
                ->get(['id', 'codigo_modular'])
                ->keyBy('codigo_modular');

            // 5.3 Preparar pivots e insertar usando Eloquent (para disparar Observer)
            foreach ($filasPivot as $p) {
                $u = $usuarios->get($p['codigo_doc']);
                if (!$u) {
                    // No debería ocurrir si upsert funcionó
                    continue;
                }

                // 🐛 DEBUG: Log antes de crear asignación
                \Log::debug('Creando asignación usuario-institución', [
                    'usuario_id' => $u->id,
                    'codigo_doc' => $p['codigo_doc'],
                    'institucion_id' => $p['institucion_id'],
                    'cargo' => $p['cargo'],
                ]);

                // ✅ Usar updateOrCreate para que el Observer se dispare
                $asignacion = UsuarioAppInstitucion::updateOrCreate(
                    [
                        'usuario_app_id' => (int) $u->id,
                        'institucion_id' => (int) $p['institucion_id'],
                    ],
                    [
                        'horario_institucion_id' => null, // Sin horario inicialmente
                        'cargo' => $p['cargo'],
                        // ⚠️ NO establecer 'estado' aquí - el Observer lo manejará
                        // El Observer verá que horario_institucion_id es null y establecerá INACTIVO
                    ]
                );

                // 🐛 DEBUG: Log después de crear asignación
                \Log::debug('Asignación creada/actualizada', [
                    'asignacion_id' => $asignacion->id,
                    'estado' => $asignacion->estado,
                    'horario_id' => $asignacion->horario_institucion_id,
                ]);
            }
        });

        // 6) Contadores chunk finales
        $resultados['exitosos_chunk'] = max(
            0,
            (int) $resultados['procesados_chunk'] - (int) $resultados['errores_chunk']
        );

        // 7) Actualizar ImportacionLog una sola vez por chunk
        if ($importLog) {
            $this->actualizarProgresoChunkRapido($importLog, $resultados);
        }

        return $resultados;
    }

    /**
     * Normaliza y valida una fila (sin DB).
     */
    private function normalizarFila(array $row): array
    {
        // Campos obligatorios
        $codigoModular = $row['codigo_modular'] ?? null;
        $dni = $row['dni'] ?? null;
        $apellidoPaterno = $row['apellido_paterno'] ?? null;
        $nombres = $row['nombres'] ?? null;
        $passwordRaw = $row['password'] ?? '';
        $codigoModularIE = $row['codigo_modular_ie'] ?? null;
        $cargo = $row['cargo'] ?? null;

        // Validaciones obligatorias
        if (empty($codigoModular))
            throw new Exception('Falta campo obligatorio: codigo_modular');
        if (empty($dni))
            throw new Exception('Falta campo obligatorio: dni');
        if (empty($apellidoPaterno))
            throw new Exception('Falta campo obligatorio: apellido_paterno');
        if (empty($nombres))
            throw new Exception('Falta campo obligatorio: nombres');
        if (empty($codigoModularIE))
            throw new Exception('Falta campo obligatorio: codigo_modular_ie');
        if (empty($cargo))
            throw new Exception('Falta campo obligatorio: cargo');

        // Validar DNI (8 dígitos)
        $dniSan = trim((string) $dni);
        if (!preg_match('/^\d{8}$/', $dniSan)) {
            throw new Exception('DNI inválido: debe ser exactamente 8 dígitos numéricos');
        }

        // Campos opcionales
        $apellidoMaterno = isset($row['apellido_materno']) && $row['apellido_materno'] !== '' ? trim((string) $row['apellido_materno']) : null;
        $sexo = isset($row['sexo']) && $row['sexo'] !== '' ? trim((string) $row['sexo']) : null;
        $telefono = isset($row['telefono']) && $row['telefono'] !== '' ? trim((string) $row['telefono']) : null;

        $codigoDoc = strtoupper(trim((string) $codigoModular));
        $sexoSan = $sexo !== null ? $this->normalizarSexo($sexo) : null;
        $cargoSan = strtoupper(trim((string) $cargo));
        $codigoIE = $this->normalizarCodigoInstitucion(trim((string) $codigoModularIE));

        return [
            'codigo_doc' => $codigoDoc,
            'dni' => $dniSan,
            'apellido_paterno' => trim((string) $apellidoPaterno),
            'apellido_materno' => $apellidoMaterno,
            'nombres' => trim((string) $nombres),
            'sexo' => $sexoSan,
            'telefono' => $telefono,
            'cargo' => $cargoSan,
            'password_raw' => is_null($passwordRaw) ? '' : trim((string) $passwordRaw),
            'codigo_ie' => $codigoIE,
        ];
    }

    /**
     * Cache instituciones por codigos (1 query).
     */
    private function buildInstitucionesCache(array $codigosIE): Collection
    {
        $codigosIE = array_values(array_unique(array_filter($codigosIE)));

        if (empty($codigosIE)) {
            return collect();
        }

        return Institucion::whereIn('codigo_modular_ie', $codigosIE)
            ->get(['id', 'codigo_modular_ie'])
            ->keyBy(fn($i) => $this->normalizarCodigoInstitucion((string) $i->codigo_modular_ie));
    }

    /**
     * Error detalle estandar.
     */
    private function armarErrorDetalle(int $numeroFila, array $rowArray, string $motivo): array
    {
        $requeridos = [
            'codigo_modular',
            'apellido_paterno',
            'apellido_materno',
            'nombres',
            'sexo',
            'codigo_modular_ie',
        ];

        $faltantes = [];
        foreach ($requeridos as $k) {
            if (empty($rowArray[$k])) {
                $faltantes[] = $k;
            }
        }

        return [
            'fila' => $numeroFila,
            'codigo_docente' => $rowArray['codigo_modular'] ?? null,
            'docente' => trim(
                ($rowArray['apellido_paterno'] ?? '') . ' ' .
                ($rowArray['apellido_materno'] ?? '') . ', ' .
                ($rowArray['nombres'] ?? '')
            ),
            'codigo_modular_ie' => $rowArray['codigo_modular_ie'] ?? null,
            'motivo' => $motivo,
            'faltantes' => $faltantes,
        ];
    }

    /**
     * Actualiza contadores y errores (1 sola vez por chunk).
     * Evita JSON gigantesco reescrito por fila.
     */
    private function actualizarProgresoChunkRapido(ImportacionLog $importLog, array $resultados): void
    {
        $procesados = (int) ($resultados['procesados_chunk'] ?? 0);
        $exitosos = (int) ($resultados['exitosos_chunk'] ?? 0);
        $errores = (int) ($resultados['errores_chunk'] ?? 0);

        // Incrementos atómicos
        ImportacionLog::whereKey($importLog->id)->update([
            'procesados' => DB::raw("procesados + {$procesados}"),
            'exitosos' => DB::raw("exitosos + {$exitosos}"),
            'errores_count' => DB::raw("errores_count + {$errores}"),
        ]);

        // Errores detalle: merge una vez por chunk
        if (!empty($resultados['errores_detalle'])) {
            $importLog->refresh();
            $actual = $importLog->errores_detalle ?? [];
            $actual = is_array($actual) ? $actual : [];

            $importLog->update([
                'errores_detalle' => array_merge($actual, $resultados['errores_detalle']),
            ]);
        }
    }

    /**
     * Normaliza el código de institución.
     */
    private function normalizarCodigoInstitucion(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        if (ctype_digit($codigo) && strlen($codigo) < 7) {
            return str_pad($codigo, 7, '0', STR_PAD_LEFT);
        }

        return $codigo;
    }

    private function normalizarSexo(string $sexoRaw): string
    {
        $s = trim($sexoRaw);
        $key = mb_strtolower($s, 'UTF-8');
        $key = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $key);

        $map = [
            'masculino' => 'M',
            'm' => 'M',
            'hombre' => 'M',
            'femenino' => 'F',
            'f' => 'F',
            'mujer' => 'F',
        ];

        if (!isset($map[$key])) {
            throw new Exception("Sexo inválido: '{$s}'. Use Masculino/Femenino (o M/F)");
        }

        return $map[$key];
    }

    /**
     * Genera una contraseña automática usando: primer_nombre + últimos_4_dígitos_dni
     * 
     * Ejemplos:
     * - "MARIA ISABEL" + "12345678" → "maria5678"
     * - "GLADYS NANCY" + "87654321" → "gladys4321"
     * - "ROCIO DEL PILAR" + "11223344" → "rocio3344"
     */
    private function generarPasswordAutomatica(string $nombres, string $dni): string
    {
        // 1. Extraer el primer nombre
        $nombreCompleto = trim($nombres);
        $partes = explode(' ', $nombreCompleto);
        $primerNombre = $partes[0] ?? '';

        // 2. Normalizar a minúsculas y sin acentos
        $primerNombre = mb_strtolower($primerNombre, 'UTF-8');
        $primerNombre = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $primerNombre
        );

        // 3. Obtener los últimos 4 dígitos del DNI
        $ultimos4Dni = substr($dni, -4);

        // 4. Retornar combinación
        return $primerNombre . $ultimos4Dni;
    }
}
