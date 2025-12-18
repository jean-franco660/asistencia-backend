<?php

namespace App\Services;

use App\Imports\UsuariosAppImport;
use App\Models\ImportacionLog;
use App\Models\Institucion;
use App\Models\UsuarioApp;
use DomainException;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

        if ($importLog->estado !== 'processing') {
            $importLog->update([
                'estado' => 'processing',
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
     * Procesar un chunk de docentes.
     */
    public function procesarChunk(Collection $rows, ?ImportacionLog $importLog = null, int $offset = 0): array
    {
        $resultados = [
            'procesados' => 0,
            'creados' => 0,
            'actualizados' => 0,
            'errores' => 0,
            'errores_detalle' => [],
        ];

        // Cache de instituciones por chunk
        $instCache = $this->buildInstitucionesCacheFromRows($rows);

        foreach ($rows as $index => $row) {
            $numeroFila = $offset + $index + 2; // +2 por encabezado

            $rowArray = is_array($row) ? $row : $row->toArray();

            try {
                $accion = $this->procesarFila($rowArray, $instCache);

                if ($accion === 'creado') {
                    $resultados['creados']++;
                } else {
                    $resultados['actualizados']++;
                }

                $resultados['procesados']++;
            } catch (Exception $e) {
                $resultados['errores']++;

                // ✅ CORREGIDO: Campos correctos
                $requeridos = [
                    'codigo_modular',  // ← Sin '_docente'
                    'apellido_paterno',
                    'apellido_materno',
                    'nombres',
                    'sexo',
                    'codigo_modular_ie'
                ];

                $faltantes = [];
                foreach ($requeridos as $k) {
                    if (empty($rowArray[$k])) {
                        $faltantes[] = $k;
                    }
                }

                $resultados['errores_detalle'][] = [
                    'fila' => $numeroFila,
                    'codigo_docente' => $rowArray['codigo_modular'] ?? null,  // ← Corregido
                    'docente' => trim(
                        ($rowArray['apellido_paterno'] ?? '') . ' ' .
                        ($rowArray['apellido_materno'] ?? '') . ', ' .
                        ($rowArray['nombres'] ?? '')
                    ),
                    'codigo_modular_ie' => $rowArray['codigo_modular_ie'] ?? null,
                    'motivo' => $e->getMessage(),
                    'faltantes' => $faltantes,
                ];

                Log::warning("Error al importar docente (chunk)", [
                    'fila' => $numeroFila,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($importLog) {
            $this->actualizarProgresoChunk($importLog, $resultados);
        }

        return $resultados;
    }

    /**
     * Procesar una fila individual.
     * Retorna: 'creado' | 'actualizado'
     */
    protected function procesarFila(array $row, Collection $instCache): string
    {
        // ✅ CORREGIDO: Campo correcto 'codigo_modular' (sin '_docente')
        $codigoModular = $row['codigo_modular'] ?? null;
        $apellidoPaterno = $row['apellido_paterno'] ?? null;
        $apellidoMaterno = $row['apellido_materno'] ?? null;
        $nombres = $row['nombres'] ?? null;
        $sexo = $row['sexo'] ?? null;
        $cargo = $row['cargo'] ?? 'DOCENTE';
        $passwordRaw = $row['password'] ?? null;
        $codigoModularIE = $row['codigo_modular_ie'] ?? null;

        // Validaciones
        if (empty($codigoModular))
            throw new Exception('Falta campo obligatorio: codigo_modular');
        if (empty($apellidoPaterno))
            throw new Exception('Falta campo obligatorio: apellido_paterno');
        if (empty($apellidoMaterno))
            throw new Exception('Falta campo obligatorio: apellido_materno');
        if (empty($nombres))
            throw new Exception('Falta campo obligatorio: nombres');
        if (empty($sexo))
            throw new Exception('Falta campo obligatorio: sexo');
        if (empty($codigoModularIE))
            throw new Exception('Falta campo obligatorio: codigo_modular_ie');

        // Normalización
        $codigoDoc = strtoupper(trim((string) $codigoModular));
        $sexoSan = $this->normalizarSexo((string) $sexo);
        $cargoSan = strtoupper(trim((string) $cargo));
        $codigoIE = $this->normalizarCodigoInstitucion(trim((string) $codigoModularIE));

        /** @var Institucion|null $institucion */
        $institucion = $instCache->get($codigoIE);
        if (!$institucion) {
            throw new Exception("Institución con código '{$codigoIE}' no encontrada");
        }

        return DB::transaction(function () use ($codigoDoc, $apellidoPaterno, $apellidoMaterno, $nombres, $sexoSan, $cargoSan, $passwordRaw, $institucion) {
            // ✅ Buscar por 'codigo_modular'
            $usuario = UsuarioApp::whereRaw('UPPER(codigo_modular) = ?', [$codigoDoc])->first();

            $accion = 'actualizado';

            if (!$usuario) {
                $usuario = new UsuarioApp();
                $usuario->codigo_modular = $codigoDoc;
                
                // Password: usar el del Excel o default
                $usuario->password = !empty($passwordRaw) ? (string) $passwordRaw : '12345678';
                
                $accion = 'creado';
            }

            // Actualizar datos personales
            $usuario->apellido_paterno = trim((string) $apellidoPaterno);
            $usuario->apellido_materno = trim((string) $apellidoMaterno);
            $usuario->nombres = trim((string) $nombres);
            $usuario->sexo = $sexoSan;
            $usuario->acceso_habilitado = true;

            $usuario->save();

            // ✅ CORREGIDO: Usar modelo directamente en lugar de clase estática
            $UsuarioAppInstitucion = \App\Models\UsuarioAppInstitucion::class;

            // Verificar si ya existe la asignación
            $asignacionExistente = $UsuarioAppInstitucion::where('usuario_app_id', $usuario->id)
                ->where('institucion_id', $institucion->id)
                ->first();

            if (!$asignacionExistente) {
                // ✅ CORREGIDO: Usar 'ACTIVO' directamente (string del ENUM)
                $UsuarioAppInstitucion::create([
                    'usuario_app_id' => $usuario->id,
                    'institucion_id' => $institucion->id,
                    'horario_institucion_id' => null,
                    'cargo' => $cargoSan,
                    'estado' => 'ACTIVO',  // ← String directo
                    'fecha_inicio' => now(),
                    'fecha_fin' => null,
                ]);
            } else {
                // ✅ CORREGIDO: Actualizar sin cambiar estado si ya tiene horario
                $asignacionExistente->update([
                    'cargo' => $cargoSan,
                    // Mantener ACTIVO siempre que tiene horario, si no tiene horario dejarlo como está
                    'estado' => $asignacionExistente->horario_institucion_id ? 'ACTIVO' : $asignacionExistente->estado,
                ]);
            }

            return $accion;
        });
    }

    protected function actualizarProgresoChunk(ImportacionLog $importLog, array $resultados): void
    {
        $procesados = (int) ($resultados['procesados'] ?? 0);
        $errores = (int) ($resultados['errores'] ?? 0);

        $importLog->increment('procesados', $procesados);
        $importLog->increment('errores_count', $errores);

        $exitosDelChunk = max(0, $procesados - $errores);
        $importLog->increment('exitosos', $exitosDelChunk);

        if (!empty($resultados['errores_detalle'])) {
            $actual = $importLog->errores_detalle ?? [];
            $actual = is_array($actual) ? $actual : [];

            $importLog->update([
                'errores_detalle' => array_merge($actual, $resultados['errores_detalle']),
            ]);
        }

        Log::info("Chunk docentes procesado", [
            'import_log_id' => $importLog->id,
            'procesados_chunk' => $procesados,
            'errores_chunk' => $errores,
            'procesados_total' => $importLog->procesados,
            'errores_total' => $importLog->errores_count,
        ]);
    }

    private function buildInstitucionesCacheFromRows(Collection $rows): Collection
    {
        $codigosIE = $rows->pluck('codigo_modular_ie')
            ->filter()
            ->map(fn($v) => $this->normalizarCodigoInstitucion(trim((string) $v)))
            ->unique()
            ->values()
            ->toArray();

        return Institucion::whereIn('codigo_modular_ie', $codigosIE)
            ->get()
            ->keyBy(fn($i) => $this->normalizarCodigoInstitucion(trim((string) $i->codigo_modular_ie)));
    }

    /**
     * Normaliza el código de institución.
     */
    private function normalizarCodigoInstitucion(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        // Si es numérico y tiene menos de 7 dígitos, rellenar con ceros
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
}