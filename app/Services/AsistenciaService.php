<?php

namespace App\Services;

use App\Models\Feriado;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use App\Models\Institucion;

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
     * Calcular estado de la asistencia (a_tiempo, tarde, salida_antes)
     */
    public function calcularEstado(Carbon $fecha, string $tipo, object $horario): string
    {
        $horaMarcada = $fecha->format('H:i:s');

        if ($tipo === 'entrada') {
            $horaEntradaMax = Carbon::parse($horario->hora_entrada)
                ->addMinutes($horario->tolerancia_minutos)
                ->format('H:i:s');

            return ($horaMarcada <= $horaEntradaMax) ? 'a_tiempo' : 'tarde';
        } else {
            return ($horaMarcada < $horario->hora_salida) ? 'salida_antes' : 'a_tiempo';
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
}