<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Representa el registro diario de asistencia de un usuario a una institución.
 *
 * Cada instancia actúa como cabecera del día: consolida el estado general
 * (PRESENTE, TARDANZA, FALTA, JUSTIFICADO), la hora de entrada y salida
 * resumidas, y los minutos de tardanza. Las marcaciones individuales
 * (ENTRADA / SALIDA) se almacenan en AsistenciaDiaria y se vinculan
 * mediante la relación `marcaciones`.
 *
 * Tabla: asistencias
 * Relaciones principales: usuario (UsuarioApp), institucion, horario (HorarioInstitucion), marcaciones (AsistenciaDiaria)
 *
 * @property \Illuminate\Support\Carbon|null $fecha_hora
 * @property \Illuminate\Support\Carbon|null $fecha
 */
class Asistencia extends Model
{
    protected $table = 'asistencias';

    // Tipos de registro
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

    protected $fillable = [
        'usuario_app_id',
        'institucion_id',
        'horario_institucion_id',

        'fecha',
        'estado_diario', // PRESENTE, TARDANZA, FALTA, JUSTIFICADO
        'hora_entrada',
        'hora_salida',
        'minutos_tardanza',
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

    // Los accessors de selfie_url y similares se trasladaron a AsistenciaDiaria

    /**
     * Serializa las fechas incluyendo el desplazamiento de zona horaria explícito.
     * Garantiza que las respuestas de la API siempre incluyan la información
     * de zona horaria en formato ISO 8601 con offset (ej: 2025-01-15T08:00:00-05:00).
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    /**
     * Usuario de la app al que pertenece esta asistencia.
     * Incluye registros eliminados con `withTrashed` para preservar la trazabilidad histórica.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id')->withTrashed();
    }

    /**
     * Institución en la que se registró la asistencia.
     */
    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    /**
     * Horario institucional vigente al momento de registrar la asistencia.
     */
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

    /**
     * Filtra asistencias que aún no han sido sincronizadas con el servidor central.
     */
    public function scopeNoSincronizadas($query)
    {
        return $query->where('sincronizado', false);
    }

    /**
     * Filtra por el identificador del usuario de la app.
     */
    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_app_id', $usuarioId);
    }

    /**
     * Filtra asistencias de una fecha exacta.
     */
    public function scopePorFecha($query, $fecha)
    {
        return $query->whereDate('fecha', $fecha);
    }

    /**
     * Filtra asistencias dentro de un rango de fechas (ambos extremos inclusivos).
     */
    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha', [$desde, $hasta]);
    }

    /**
     * Filtra por el identificador del horario institucional.
     */
    public function scopePorHorario($query, $horarioId)
    {
        return $query->where('horario_institucion_id', $horarioId);
    }

    /**
     * Filtra registros de tipo ENTRADA.
     */
    public function scopeEntradas($query)
    {
        return $query->where('tipo', self::TIPO_ENTRADA);
    }

    /**
     * Filtra registros de tipo SALIDA.
     */
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

    protected $appends = [];

    /**
     * Retorna la situación administrativa derivada del `estado_diario`.
     * Mapea FALTA → SITUACION_FALTA, JUSTIFICADO → SITUACION_JUSTIFICADO, resto → SITUACION_NORMAL.
     */
    public function getSituacionAttribute(): string
    {
        return match ($this->estado_diario) {
            'FALTA' => self::SITUACION_FALTA,
            'JUSTIFICADO' => self::SITUACION_JUSTIFICADO,
            default => self::SITUACION_NORMAL,
        };
    }

    /**
     * Retorna el resultado de la marcación del día basado en el `estado_diario`.
     * Devuelve `null` para estados que no implican resultado de puntualidad (FALTA, JUSTIFICADO).
     */
    public function getResultadoAttribute(): ?string
    {
        return match ($this->estado_diario) {
            'TARDANZA' => self::RESULTADO_TARDE,
            'PRESENTE' => self::RESULTADO_A_TIEMPO,
            default => null,
        };
    }

    /**
     * Retorna la primera marcación del día, que corresponde normalmente a la entrada.
     * Se usa como base para obtener latitud, longitud y foto del día.
     */
    protected function getMarcacionPrincipalAttribute()
    {
        return $this->marcaciones->first();
    }

    /**
     * Retorna la latitud tomada en la marcación principal del día.
     */
    public function getLatitudAttribute()
    {
        return $this->marcacion_principal?->latitud;
    }

    /**
     * Retorna la longitud tomada en la marcación principal del día.
     */
    public function getLongitudAttribute()
    {
        return $this->marcacion_principal?->longitud;
    }

    /**
     * Indica si la marcación principal fue realizada dentro del rango geográfico permitido.
     */
    public function getDentroRangoAttribute(): bool
    {
        return (bool) $this->marcacion_principal?->dentro_rango;
    }

    /**
     * Retorna la URL de la primera marcación del día que tenga foto adjunta.
     */
    public function getFotoAttribute()
    {
        return $this->marcaciones->first(fn($m) => !empty($m->foto_url))?->foto_url;
    }

    /**
     * Retorna el nombre del turno asociado al horario de esta asistencia.
     */
    public function getTurnoAttribute(): ?string
    {
        return $this->horario?->nombre_turno;
    }

    /**
     * Filtra asistencias con situación administrativa de falta (estado_diario = FALTA).
     */
    public function scopeSituacionFalta($query)
    {
        return $query->where('estado_diario', 'FALTA');
    }

    /**
     * Filtra asistencias que han sido justificadas (estado_diario = JUSTIFICADO).
     */
    public function scopeJustificadas($query)
    {
        return $query->where('estado_diario', 'JUSTIFICADO');
    }

    /**
     * Filtra asistencias sin falta ni justificación (PRESENTE o TARDANZA).
     */
    public function scopeNormales($query)
    {
        return $query->whereNotIn('estado_diario', ['FALTA', 'JUSTIFICADO']);
    }

    /**
     * Filtra asistencias con tardanza registrada.
     */
    public function scopeTardes($query)
    {
        return $query->where('estado_diario', 'TARDANZA');
    }

    /**
     * Filtra asistencias en las que al menos una marcación se realizó dentro del rango geográfico.
     */
    public function scopeDentroRango($query)
    {
        return $query->whereHas('marcaciones', function ($q) {
            $q->where('dentro_rango', true);
        });
    }

    /**
     * Filtra asistencias en las que al menos una marcación se realizó fuera del rango geográfico.
     */
    public function scopeFueraRango($query)
    {
        return $query->whereHas('marcaciones', function ($q) {
            $q->where('dentro_rango', false);
        });
    }
}
