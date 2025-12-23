<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * @property \Illuminate\Support\Carbon|null $fecha_hora
 * @property \Illuminate\Support\Carbon|null $fecha
 */
class Asistencia extends Model
{
    protected $table = 'asistencias';

    /* =========================
     * CONSTANTES - ALINEADAS CON MIGRACIÓN
     * ========================= */

    // Tipos (MAYÚSCULAS según migración)
    public const TIPO_ENTRADA = 'ENTRADA';
    public const TIPO_SALIDA = 'SALIDA';
    public const TIPO_FALTA = 'FALTA';

    // Resultados de marcación
    public const RESULTADO_PUNTUAL = 'PUNTUAL';
    public const RESULTADO_A_TIEMPO = 'A_TIEMPO';
    public const RESULTADO_TARDE = 'TARDE';
    public const RESULTADO_SALIDA_ANTES = 'SALIDA_ANTES';

    // Situación administrativa
    public const SITUACION_NORMAL = 'NORMAL';
    public const SITUACION_FALTA = 'FALTA';
    public const SITUACION_JUSTIFICADO = 'JUSTIFICADO';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

    protected $fillable = [
        'usuario_app_id',
        'institucion_id',
        'horario_institucion_id',

        // Fechas según migración
        'fecha',

        // Estado diario (Header)
        'estado_diario', // PRESENTE, TARDANZA, FALTA

        // Resumen
        'hora_entrada',
        'hora_salida',
        'minutos_tardanza',

        // Auditoría
        'observacion',
        'revisado_por_usuario_web_id',
        'revisado_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'revisado_at' => 'datetime',
    ];

    protected $attributes = [
        'estado_diario' => 'FALTA',
    ];

    // NOTE: removed appends for selfie_url etc as they belong to details now

    /**
     * Serialize dates to include explicit timezone offset
     * Ensures API responses always include timezone information (UTC)
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP'); // ISO 8601 with timezone offset
    }

    /* =========================
     * RELACIONES
     * ========================= */

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id')->withTrashed();
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(HorarioInstitucion::class, 'horario_institucion_id');
    }

    /**
     * Detalle de marcaciones (Entradas/Salidas)
     */
    public function marcaciones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AsistenciaDiaria::class, 'asistencia_id');
    }


    /**
     * Crea una justificación para esta asistencia.
     */
    public function crearJustificacion(string $tipo, string $motivo): Justificacion
    {
        return Justificacion::crearPorFaltaParcial($this, $tipo, $motivo);
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopeNoSincronizadas($query)
    {
        return $query->where('sincronizado', false);
    }

    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_app_id', $usuarioId);
    }

    public function scopePorFecha($query, $fecha)
    {
        return $query->whereDate('fecha', $fecha);
    }

    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha', [$desde, $hasta]);
    }

    public function scopePorHorario($query, $horarioId)
    {
        return $query->where('horario_institucion_id', $horarioId);
    }

    public function scopeEntradas($query)
    {
        return $query->where('tipo', self::TIPO_ENTRADA);
    }

    public function scopeSalidas($query)
    {
        return $query->where('tipo', self::TIPO_SALIDA);
    }

    /**
     * Faltas completas (registro tipo FALTA).
     */
    public function scopeFaltas($query)
    {
        return $query->where('tipo', self::TIPO_FALTA);
    }

    /**
     * Situación administrativa en FALTA (incluye faltas completas y cualquier registro marcado como FALTA).
     */
    protected $appends = [
        'situacion',
        'resultado',
        'latitud',
        'longitud',
        'dentro_rango',
        'foto',
    ];

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getSituacionAttribute(): string
    {
        return match ($this->estado_diario) {
            'FALTA' => self::SITUACION_FALTA,
            'JUSTIFICADO' => self::SITUACION_JUSTIFICADO,
            default => self::SITUACION_NORMAL,
        };
    }

    public function getResultadoAttribute(): ?string
    {
        return match ($this->estado_diario) {
            'TARDANZA' => self::RESULTADO_TARDE,
            'PRESENTE' => self::RESULTADO_A_TIEMPO,
            default => null,
        };
    }

    // Helper para obtener la marcación relevante (usualmente la primera/entrada)
    protected function getMarcacionPrincipalAttribute()
    {
        return $this->marcaciones->first();
    }

    public function getLatitudAttribute()
    {
        return $this->marcacion_principal?->latitud;
    }

    public function getLongitudAttribute()
    {
        return $this->marcacion_principal?->longitud;
    }

    public function getDentroRangoAttribute(): bool
    {
        return (bool) $this->marcacion_principal?->dentro_rango;
    }

    public function getFotoAttribute()
    {
        return $this->marcaciones->first(fn($m) => !empty($m->foto_url))?->foto_url;
    }

    public function getTurnoAttribute(): ?string
    {
        return $this->horario?->nombre_turno;
    }

    public function scopeSituacionFalta($query)
    {
        return $query->where('situacion', self::SITUACION_FALTA);
    }

    public function scopeJustificadas($query)
    {
        return $query->where('situacion', self::SITUACION_JUSTIFICADO);
    }

    public function scopeNormales($query)
    {
        return $query->where('situacion', self::SITUACION_NORMAL);
    }

    public function scopeTardes($query)
    {
        return $query->where('resultado', self::RESULTADO_TARDE);
    }

    public function scopeDentroRango($query)
    {
        return $query->where('dentro_rango', true);
    }

    public function scopeFueraRango($query)
    {
        return $query->where('dentro_rango', false);
    }
}
