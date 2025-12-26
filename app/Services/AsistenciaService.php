<?php

namespace App\Services;

use App\Models\Asistencia;
use App\Models\Feriado;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use App\Models\Institucion;
use App\Models\HorarioInstitucion;

class AsistenciaService
{
    /**
     * Validar si la fecha es feriado nacional
     */
    public function validarFeriadoNacional(Carbon $fecha): ?Feriado
    {
        return Feriado::where('tipo', 'nacional')
            ->where('dia', $fecha->day)
            ->where('mes', $fecha->month)
            ->where('activo', true)
            ->first();
    }

    /**
     * Validar si la fecha es feriado institucional
     */
    public function validarFeriadoInstitucional(Carbon $fecha, int $institucionId): ?Feriado
    {
        return Feriado::where('tipo', 'institucional')
            ->where('institucion_id', $institucionId)
            ->where('dia', $fecha->day)
            ->where('mes', $fecha->month)
            ->where('activo', true)
            ->first();
    }

    /**
     * Obtener horario laboral para una fecha e institución
     */
    public function obtenerHorarioLaboral(Carbon $fecha, int $institucionId): ?object
    {
        $diaMapa = [
            'monday' => 'L',
            'tuesday' => 'M',
            'wednesday' => 'X',
            'thursday' => 'J',
            'friday' => 'V',
            'saturday' => 'S',
            'sunday' => 'D'
        ];

        $diaHoy = $diaMapa[strtolower($fecha->englishDayOfWeek)] ?? null;

        if (!$diaHoy) {
            return null;
        }

        return DB::table('horarios_institucion')
            ->where('institucion_id', $institucionId)
            ->whereJsonContains('dias_semana', $diaHoy)
            ->where('activo', true)
            ->first();
    }

    /**
     * Calcular estado de la asistencia (A_TIEMPO, TARDE, SALIDA_ANTES)
     */
    /**
     * Calcular estado de la asistencia (PUNTUAL, A_TIEMPO, TARDE, SALIDA_ANTES)
     * Retorna array con resultado detalle y estado diario sugerido
     */
    public function calcularEstado(Carbon $fecha, string $tipo, object $horario): array
    {
        $horaMarcada = $fecha->copy();

        if ($tipo === Asistencia::TIPO_ENTRADA) {
            $fechaBase = $fecha->format('Y-m-d');
            $entradaProgramada = Carbon::parse("$fechaBase {$horario->hora_entrada}", 'America/Lima');

            // Tolerancia (Asegurar que sea entero)
            $tolerancia = (int) ($horario->tolerancia_entrada_minutos ?? 0);
            $entradaMaxima = $entradaProgramada->copy()->addMinutes($tolerancia);

            $resultado = Asistencia::RESULTADO_TARDE;
            $estadoDiario = 'TARDANZA'; // Default si es tarde
            $minutosTardanza = 0;

            if ($horaMarcada->lte($entradaProgramada)) {
                // Llegó antes o exacto (PUNTUAL)
                $resultado = Asistencia::RESULTADO_PUNTUAL;
                $estadoDiario = 'PRESENTE';
                $minutosTardanza = 0;
            } elseif ($horaMarcada->lte($entradaMaxima)) {
                // Llegó dentro de tolerancia (A TIEMPO)
                $resultado = Asistencia::RESULTADO_A_TIEMPO;
                $estadoDiario = 'PRESENTE';
                $minutosTardanza = 0;
            } else {
                // Llegó tarde (fuera de tolerancia)
                // Usar abs() por seguridad y cast a int
                $minutosTardanza = (int) abs($horaMarcada->diffInMinutes($entradaMaxima, false));
                $resultado = Asistencia::RESULTADO_TARDE;
                $estadoDiario = 'TARDANZA';
            }

            return [
                'resultado' => $resultado,
                'minutos_tardanza' => $minutosTardanza,
                'estado_diario' => $estadoDiario
            ];

        } else {
            // Lógica SALIDA
            $fechaBase = $fecha->format('Y-m-d');
            $salidaProgramada = Carbon::parse("$fechaBase {$horario->hora_salida}", 'America/Lima');

            // Tolerancia para salida
            $tolerancia = (int) ($horario->tolerancia_salida_minutos ?? 0);
            $salidaMaxima = $salidaProgramada->copy()->addMinutes($tolerancia);

            if ($horaMarcada->lt($salidaProgramada)) {
                // Salió antes de la hora programada
                return [
                    'resultado' => Asistencia::RESULTADO_SALIDA_ANTES,
                    'minutos_tardanza' => 0,
                    'estado_diario' => 'PRESENTE',
                    'requiere_observacion' => false,
                ];
            } elseif ($horaMarcada->lte($salidaMaxima)) {
                // Salió a tiempo (dentro de la ventana permitida)
                return [
                    'resultado' => Asistencia::RESULTADO_A_TIEMPO,
                    'minutos_tardanza' => 0,
                    'estado_diario' => 'PRESENTE',
                    'requiere_observacion' => false,
                ];
            } else {
                // Salió tarde (después de hora + tolerancia)
                // Convertir a entero y usar valor absoluto para evitar decimales/negativos confusos
                $minutosTardeSalida = (int) abs($horaMarcada->diffInMinutes($salidaMaxima));
                return [
                    'resultado' => Asistencia::RESULTADO_TARDE,
                    'minutos_tardanza' => 0, // No se suma tardanza de salida al header
                    'estado_diario' => 'PRESENTE',
                    'requiere_observacion' => true,
                    'motivo_observacion' => "Salió {$minutosTardeSalida} minutos después del horario permitido",
                ];
            }
        }
    }

    /**
     * Guardar foto (Base64) en S3 o disco local según configuración
     */
    public function guardarFoto(?string $fotoBase64, string $contexto = 'store'): ?string
    {
        if (empty($fotoBase64)) {
            return null;
        }

        try {
            Log::info("📸 Procesando foto Base64 ({$contexto})...");

            $fotoData = base64_decode($fotoBase64);

            if ($fotoData === false) {
                Log::error("❌ Error decodificando Base64 en {$contexto}");
                return null;
            }

            $fileName = 'selfies/' . uniqid('selfie_') . '.jpg';
            $useS3 = env('USE_S3', false);

            if ($useS3) {
                // Guardar en S3
                Storage::disk('s3')->put($fileName, $fotoData, 'public');
                Log::info("✅ Foto guardada en S3 ({$contexto})", ['path' => $fileName]);
            } else {
                // Guardar en disco local
                Storage::disk('public')->put($fileName, $fotoData);
                Log::info("✅ Foto guardada en disco local ({$contexto})", ['path' => $fileName]);
            }

            return $fileName;

        } catch (\Throwable $e) {
            Log::error("❌ Error guardando foto ({$contexto}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validar si una fecha es laborable (no feriado + tiene horario)
     */
    public function esDiaLaborable(Carbon $fecha, int $institucionId): array
    {
        // Validar feriado nacional
        $feriadoNacional = $this->validarFeriadoNacional($fecha);
        if ($feriadoNacional) {
            return [
                'laborable' => false,
                'motivo' => "Feriado Nacional: {$feriadoNacional->descripcion}",
                'horario' => null,
            ];
        }

        // Validar feriado institucional
        $feriadoInstitucional = $this->validarFeriadoInstitucional($fecha, $institucionId);
        if ($feriadoInstitucional) {
            return [
                'laborable' => false,
                'motivo' => "Feriado Institucional: {$feriadoInstitucional->descripcion}",
                'horario' => null,
            ];
        }

        // Validar horario laboral
        $horario = $this->obtenerHorarioLaboral($fecha, $institucionId);
        if (!$horario) {
            return [
                'laborable' => false,
                'motivo' => "Día no laborable",
                'horario' => null,
            ];
        }

        return [
            'laborable' => true,
            'motivo' => null,
            'horario' => $horario,
        ];
    }

    /**
     * Mapeo de días de la semana
     */
    public function getDiaMapa(): array
    {
        return [
            'monday' => 'L',
            'tuesday' => 'M',
            'wednesday' => 'X',
            'thursday' => 'J',
            'friday' => 'V',
            'saturday' => 'S',
            'sunday' => 'D'
        ];
    }

    /**
     * Validar si una fecha es laborable usando el HORARIO ASIGNADO (source of truth).
     * - Valida feriados nacional/institucional
     * - Valida que el horario exista, esté activo, pertenezca a la institución
     * - Valida que el día esté dentro de dias_semana del horario
     */
    public function esDiaLaborableConHorario(
        Carbon $fecha,
        int $institucionId,
        int $horarioInstitucionId
    ): array {
        // 1) Feriado nacional
        $feriadoNacional = $this->validarFeriadoNacional($fecha);
        if ($feriadoNacional) {
            return [
                'laborable' => false,
                'motivo' => "Feriado Nacional: {$feriadoNacional->descripcion}",
                'horario' => null,
            ];
        }

        // 2) Feriado institucional
        $feriadoInstitucional = $this->validarFeriadoInstitucional($fecha, $institucionId);
        if ($feriadoInstitucional) {
            return [
                'laborable' => false,
                'motivo' => "Feriado Institucional: {$feriadoInstitucional->descripcion}",
                'horario' => null,
            ];
        }

        // 3) Cargar horario asignado
        /** @var \App\Models\HorarioInstitucion|null $horario */
        $horario = HorarioInstitucion::query()
            ->where('id', $horarioInstitucionId)
            ->where('institucion_id', $institucionId)
            ->where('activo', true)
            ->first();

        if (!$horario) {
            return [
                'laborable' => false,
                'motivo' => 'Horario asignado inválido o inactivo',
                'horario' => null,
            ];
        }

        // 4) Día de semana mapeado (L/M/X/J/V/S/D)
        $diaMapa = $this->getDiaMapa();
        $diaHoy = $diaMapa[strtolower($fecha->englishDayOfWeek)] ?? null;

        if (!$diaHoy) {
            return [
                'laborable' => false,
                'motivo' => 'Día inválido',
                'horario' => null,
            ];
        }

        // 5) Normalizar dias_semana a array (por si viene como JSON string)
        $dias = $horario->dias_semana;

        if (is_string($dias)) {
            $decoded = json_decode($dias, true);
            $dias = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($dias)) {
            $dias = [];
        }

        // Si dias_semana es null/[] => no laborable
        if (empty($dias) || !in_array($diaHoy, $dias, true)) {
            return [
                'laborable' => false,
                'motivo' => 'Día no laborable para tu horario asignado',
                'horario' => null,
            ];
        }

        return [
            'laborable' => true,
            'motivo' => null,
            'horario' => $horario,
        ];
    }


    /**
     * Calcular distancia geodésica (Haversine) en metros
     */
    private function haversineGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000): float
    {
        // Convertir de grados a radianes
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    /**
     * Verifica si la coordenada está dentro del radio de la institución
     */
    public function estaDentroRango($lat, $lng, Institucion $institucion): bool
    {
        $distancia = $this->haversineGreatCircleDistance(
            $lat,
            $lng,
            $institucion->latitud,
            $institucion->longitud
        );

        // Retorna true si la distancia es menor o igual al radio de la institución
        // Si no tiene radio definido, asumimos 150m como fallback razonable (o podría ser false)
        $radioPermitido = $institucion->radio ?? 150.0;

        return $distancia <= $radioPermitido;
    }

    /**
     * Guardar foto desde un archivo subido (UploadedFile)
     */
    public function guardarFotoArchivo(?UploadedFile $file, string $contexto = 'store'): ?string
    {
        if (!$file) {
            return null;
        }

        try {
            Log::info("📸 Procesando foto Archivo ({$contexto})...");

            $fileName = 'selfies/' . uniqid('selfie_') . '.jpg';
            $useS3 = env('USE_S3', false);

            // Contenido del archivo
            $fileContent = file_get_contents($file->getRealPath());

            if ($useS3) {
                // Guardar en S3
                Storage::disk('s3')->put($fileName, $fileContent, 'public');
                Log::info("✅ Foto guardada en S3 ({$contexto})", ['path' => $fileName]);
            } else {
                // Guardar en disco local
                Storage::disk('public')->put($fileName, $fileContent);
                Log::info("✅ Foto guardada en disco local ({$contexto})", ['path' => $fileName]);
            }

            return $fileName;

        } catch (\Throwable $e) {
            Log::error("❌ Error guardando foto archivo ({$contexto}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener estado completo de marcación para la UI (App)
     * Retorna estructura con server_now, next_action, windows, mensaje
     */
    public function obtenerEstadoMarcacion(
        int $usuarioAppId,
        int $institucionId,
        int $horarioInstitucionId,
        Carbon $fecha
    ): array {
        $serverNow = now('America/Lima');

        // 1. Obtener horario
        $horario = HorarioInstitucion::where('id', $horarioInstitucionId)
            ->where('institucion_id', $institucionId)
            ->where('activo', true)
            ->first();

        if (!$horario) {
            return [
                'server_now' => $serverNow->toIso8601String(),
                'next_action' => 'NONE',
                'windows' => null,
                'mensaje_estado' => 'Horario no disponible',
                'puede_marcar' => false,
            ];
        }

        // 2. Consultar historial del día (Header)
        $fechaStr = $fecha->format('Y-m-d');
        $asistenciaDelDia = Asistencia::where('usuario_app_id', $usuarioAppId)
            ->where('institucion_id', $institucionId)
            ->where('fecha', $fechaStr)
            ->with('marcaciones')
            ->first();

        // 3. Determinar qué marcaciones existen
        $tieneEntrada = false;
        $tieneSalida = false;

        if ($asistenciaDelDia && $asistenciaDelDia->marcaciones) {
            foreach ($asistenciaDelDia->marcaciones as $marcacion) {
                if ($marcacion->tipo === 'ENTRADA') {
                    $tieneEntrada = true;
                }
                if ($marcacion->tipo === 'SALIDA') {
                    $tieneSalida = true;
                }
            }
        }

        // 4. Determinar next_action
        if (!$tieneEntrada) {
            $nextAction = 'ENTRADA';
        } elseif ($tieneEntrada && !$tieneSalida) {
            $nextAction = 'SALIDA';
        } else {
            // Ya marcó entrada y salida
            return [
                'server_now' => $serverNow->toIso8601String(),
                'next_action' => 'NONE',
                'windows' => null,
                'mensaje_estado' => 'Turno completado',
                'puede_marcar' => false,
                'horario' => [
                    'turno' => $horario->nombre_turno,
                    'hora_entrada' => $horario->hora_entrada,
                    'hora_salida' => $horario->hora_salida,
                    'tolerancia_entrada_minutos' => $horario->tolerancia_entrada_minutos,
                    'tolerancia_salida_minutos' => $horario->tolerancia_salida_minutos,
                ],
            ];
        }

        // 5. Calcular ventanas según next_action
        $fechaBase = $fecha->format('Y-m-d');

        /** @var array{entrada: array{start: string, end: string}|null, salida: array{start: string, end: string}|null} $windows */
        $windows = ['entrada' => null, 'salida' => null];

        if ($nextAction === 'ENTRADA') {
            $horaEntrada = Carbon::parse("$fechaBase {$horario->hora_entrada}", 'America/Lima');
            $windowStart = $horaEntrada->copy();
            // Permitir marcación tardía: La ventana se cierra al FINAL del turno (hora de salida), no al acabar la tolerancia.
            $horaSalida = Carbon::parse("$fechaBase {$horario->hora_salida}", 'America/Lima');
            $windowEnd = $horaSalida->copy(); // Fin de ventana = Fin del turno

            $windows['entrada'] = [
                'start' => $windowStart->toIso8601String(),
                'end' => $windowEnd->toIso8601String(),
            ];
        } else { // SALIDA
            $horaSalida = Carbon::parse("$fechaBase {$horario->hora_salida}", 'America/Lima');
            // Ventana estricta: Desde la hora de salida hasta hora_salida + tolerancia
            $windowStart = $horaSalida->copy();
            $windowEnd = $horaSalida->copy()->addMinutes($horario->tolerancia_salida_minutos);

            $windows['salida'] = [
                'start' => $windowStart->toIso8601String(),
                'end' => $windowEnd->toIso8601String(),
            ];
        }

        // 6. Determinar si puede marcar AHORA
        $dentroDeVentana = false;
        $mensajeEstado = '';

        if ($nextAction === 'ENTRADA' && isset($windows['entrada']['start'], $windows['entrada']['end'])) {
            $ventanaInicio = Carbon::parse($windows['entrada']['start']);
            $ventanaFin = Carbon::parse($windows['entrada']['end']);

            if ($serverNow->lt($ventanaInicio)) {
                $mensajeEstado = 'Aún no inicia la ventana de entrada';
                $minutosRestantes = $serverNow->diffInMinutes($ventanaInicio, false);
                $mensajeEstado .= " (disponible en " . abs($minutosRestantes) . " minutos)";
            } elseif ($serverNow->gt($ventanaFin)) {
                $mensajeEstado = 'Ventana de entrada cerrada';
            } else {
                $dentroDeVentana = true;
                $mensajeEstado = 'Dentro de ventana de entrada';
            }
        } elseif ($nextAction === 'SALIDA' && isset($windows['salida']['start'], $windows['salida']['end'])) {
            $ventanaInicio = Carbon::parse($windows['salida']['start']);
            $ventanaFin = Carbon::parse($windows['salida']['end']);

            if ($serverNow->lt($ventanaInicio)) {
                $mensajeEstado = 'Aún no inicia la ventana de salida';
                $minutosRestantes = $serverNow->diffInMinutes($ventanaInicio, false);
                $mensajeEstado .= " (disponible en " . abs($minutosRestantes) . " minutos)";
            } elseif ($serverNow->gt($ventanaFin)) {
                $mensajeEstado = 'Ventana de salida cerrada';
            } else {
                $dentroDeVentana = true;
                $mensajeEstado = 'Dentro de ventana de salida';
            }
        }

        return [
            'server_now' => $serverNow->toIso8601String(),
            'next_action' => $nextAction,
            'windows' => $windows,
            'mensaje_estado' => $mensajeEstado,
            'puede_marcar' => $dentroDeVentana,
            'horario' => [
                'turno' => $horario->nombre_turno,
                'hora_entrada' => $horario->hora_entrada,
                'hora_salida' => $horario->hora_salida,
                'tolerancia_entrada_minutos' => $horario->tolerancia_entrada_minutos,
                'tolerancia_salida_minutos' => $horario->tolerancia_salida_minutos,
            ],
        ];
    }
}