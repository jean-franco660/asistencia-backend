<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * @property \Illuminate\Support\Carbon $fecha_hora
 * @property \Illuminate\Support\Carbon $fecha
 */
class Asistencia extends Model
{
    protected $table = 'asistencias';

    /* =========================
     * CONSTANTES - ALINEADAS CON MIGRACIÓN
     * ========================= */

    // Tipos (MAYÚSCULAS según migración)
    public const TIPO_ENTRADA = 'ENTRADA';
    public const TIPO_SALIDA  = 'SALIDA';

    // Resultados de marcación
    public const RESULTADO_A_TIEMPO      = 'A_TIEMPO';
    public const RESULTADO_TARDE         = 'TARDE';
    public const RESULTADO_SALIDA_ANTES  = 'SALIDA_ANTES';

    // Situación administrativa
    public const SITUACION_NORMAL      = 'NORMAL';
    public const SITUACION_FALTA       = 'FALTA';
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
        'fecha_hora',
        
        // Tipo y estado
        'tipo',
        'resultado',
        'situacion',
        
        // Geolocalización
        'dentro_rango',
        'latitud',
        'longitud',
        
        // Evidencia
        'foto',
        
        // Sync
        'sincronizado',
    ];

    protected $casts = [
        'fecha'           => 'date',
        'fecha_hora'      => 'datetime',
        'dentro_rango'    => 'boolean',
        'sincronizado'    => 'boolean',
        'latitud'         => 'decimal:7',
        'longitud'        => 'decimal:7',
    ];

    protected $attributes = [
        'sincronizado'   => false,
        'situacion'      => self::SITUACION_NORMAL,
        'dentro_rango'   => false,
    ];

    protected $appends = [
        'selfie_url',
        'fecha_hora_formateada',
    ];

    /* =========================
     * RELACIONES
     * ========================= */

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(
            HorarioInstitucion::class,
            'horario_institucion_id'
        );
    }

    /**
     * Justificación asociada (si existe)
     * 
     * Una asistencia parcial (solo entrada o solo salida) puede tener justificación.
     * Las faltas completas (sin asistencia) también tienen justificación, pero no
     * están vinculadas aquí (asistencia_id = null en justificaciones).
     */
    public function justificacion(): HasOne
    {
        return $this->hasOne(Justificacion::class, 'asistencia_id');
    }

    /**
     * Asistencia complementaria del mismo día
     * Ejemplo: Si esta es ENTRADA, busca la SALIDA del mismo día
     */
    public function asistenciaComplementaria(): ?self
    {
        $tipoComplementario = $this->esEntrada() 
            ? self::TIPO_SALIDA 
            : self::TIPO_ENTRADA;

        return static::where('usuario_app_id', $this->usuario_app_id)
                     ->where('institucion_id', $this->institucion_id)
                     ->where('horario_institucion_id', $this->horario_institucion_id)
                     ->where('fecha', $this->fecha)
                     ->where('tipo', $tipoComplementario)
                     ->first();
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getFechaHoraFormateadaAttribute(): string
    {
        return $this->fecha_hora
            ? $this->fecha_hora->format('d/m/Y H:i:s')
            : '';
    }

    public function getFechaFormateadaAttribute(): string
    {
        return $this->fecha
            ? $this->fecha->format('d/m/Y')
            : '';
    }

    public function getSelfieUrlAttribute(): ?string
    {
        if (!$this->foto) {
            return null;
        }

        try {
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk('s3');
            return $disk->url($this->foto);
        } catch (\Throwable $e) {
            \Log::error("Error S3 asistencia {$this->id}: {$e->getMessage()}");
            return null;
        }
    }

    /* =========================
     * HELPERS DE DOMINIO
     * ========================= */

    public function esEntrada(): bool
    {
        return $this->tipo === self::TIPO_ENTRADA;
    }

    public function esSalida(): bool
    {
        return $this->tipo === self::TIPO_SALIDA;
    }

    public function estaJustificada(): bool
    {
        return $this->situacion === self::SITUACION_JUSTIFICADO;
    }

    public function esFalta(): bool
    {
        return $this->situacion === self::SITUACION_FALTA;
    }

    public function esATiempo(): bool
    {
        return $this->resultado === self::RESULTADO_A_TIEMPO;
    }

    public function esTarde(): bool
    {
        return $this->resultado === self::RESULTADO_TARDE;
    }

    public function salidaAntes(): bool
    {
        return $this->resultado === self::RESULTADO_SALIDA_ANTES;
    }

    /**
     * Verifica si la marcación está completa (tiene entrada Y salida)
     */
    public function tieneMarcacionCompleta(): bool
    {
        return $this->asistenciaComplementaria() !== null;
    }

    /**
     * Verifica si requiere justificación
     */
    public function requiereJustificacion(): bool
    {
        // Si está justificada, no requiere otra
        if ($this->estaJustificada()) {
            return false;
        }

        // Si ya tiene justificación pendiente/aprobada, no requiere otra
        if ($this->justificacion()->exists()) {
            return false;
        }

        // Si es falta, requiere justificación
        if ($this->esFalta()) {
            return true;
        }

        // Si no tiene marcación complementaria (falta entrada o salida)
        if (!$this->tieneMarcacionCompleta()) {
            return true;
        }

        // Opcional: según política institucional, tardanzas pueden requerir justificación
        // return $this->esTarde();

        return false;
    }

    /**
     * Crea una justificación para esta asistencia
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

    public function scopeFaltas($query)
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