<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacionLog extends Model
{
    protected $table = 'importaciones_log';

    protected $fillable = [
        'usuario_id',
        'tipo',
        'archivo_original',
        'archivo_temp',
        'estado',
        'total',
        'procesados',
        'exitosos',
        'errores_count',
        'errores_detalle',
        'porcentaje',
        'iniciado_en',
        'completado_en',
    ];

    protected $casts = [
        'errores_detalle' => 'array',
        'total' => 'integer',
        'procesados' => 'integer',
        'exitosos' => 'integer',
        'errores_count' => 'integer',
        'porcentaje' => 'integer',
        'iniciado_en' => 'datetime',
        'completado_en' => 'datetime',
    ];

    /**
     * Estados posibles:
     * - pending: En cola esperando procesamiento
     * - processing: Siendo procesado
     * - completed: Completado exitosamente
     * - failed: Falló completamente
     */
    const ESTADO_PENDING = 'pending';
    const ESTADO_PROCESSING = 'processing';
    const ESTADO_COMPLETED = 'completed';
    const ESTADO_FAILED = 'failed';

    /**
     * Tipos de importación
     */
    const TIPO_INSTITUCIONES = 'instituciones';
    const TIPO_DOCENTES = 'docentes';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'usuario_id');
    }

    /**
     * Scope para filtrar por tipo
     */
    public function scopeTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Scope para filtrar por estado
     */
    public function scopeEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Scope para importaciones completadas
     */
    public function scopeCompletadas($query)
    {
        return $query->where('estado', self::ESTADO_COMPLETED);
    }

    /**
     * Scope para importaciones en progreso
     */
    public function scopeEnProgreso($query)
    {
        return $query->whereIn('estado', [self::ESTADO_PENDING, self::ESTADO_PROCESSING]);
    }

    /**
     * Scope para importaciones fallidas
     */
    public function scopeFallidas($query)
    {
        return $query->where('estado', self::ESTADO_FAILED);
    }

    /**
     * Accessor: Calcular porcentaje si no está guardado
     */
    public function getPorcentajeAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }

        if ($this->total > 0) {
            return round(($this->procesados / $this->total) * 100);
        }

        return 0;
    }

    /**
     * Accessor: Duración de la importación en segundos
     */
    public function getDuracionAttribute(): ?int
    {
        if ($this->iniciado_en && $this->completado_en) {
            return $this->completado_en->diffInSeconds($this->iniciado_en);
        }

        if ($this->iniciado_en && !$this->completado_en && $this->estado === self::ESTADO_PROCESSING) {
            return now()->diffInSeconds($this->iniciado_en);
        }

        return null;
    }

    /**
     * Accessor: Formato legible de duración
     */
    public function getDuracionFormateadaAttribute(): ?string
    {
        $duracion = $this->duracion;
        
        if ($duracion === null) {
            return null;
        }

        if ($duracion < 60) {
            return "{$duracion} segundos";
        }

        $minutos = floor($duracion / 60);
        $segundos = $duracion % 60;

        if ($minutos < 60) {
            return "{$minutos} minutos, {$segundos} segundos";
        }

        $horas = floor($minutos / 60);
        $minutos = $minutos % 60;

        return "{$horas} horas, {$minutos} minutos";
    }

    /**
     * Accessor: Tasa de éxito en porcentaje
     */
    public function getTasaExitoAttribute(): float
    {
        if ($this->procesados === 0) {
            return 0;
        }

        return round(($this->exitosos / $this->procesados) * 100, 2);
    }

    /**
     * Verificar si la importación está completada
     */
    public function estaCompletada(): bool
    {
        return $this->estado === self::ESTADO_COMPLETED;
    }

    /**
     * Verificar si la importación está en progreso
     */
    public function estaEnProgreso(): bool
    {
        return in_array($this->estado, [self::ESTADO_PENDING, self::ESTADO_PROCESSING]);
    }

    /**
     * Verificar si la importación falló
     */
    public function fallo(): bool
    {
        return $this->estado === self::ESTADO_FAILED;
    }

    /**
     * Verificar si tiene errores
     */
    public function tieneErrores(): bool
    {
        return $this->errores_count > 0;
    }

    /**
     * Obtener resumen de la importación
     */
    public function getResumenAttribute(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'total' => $this->total,
            'procesados' => $this->procesados,
            'exitosos' => $this->exitosos,
            'errores' => $this->errores_count,
            'porcentaje' => $this->porcentaje,
            'tasa_exito' => $this->tasa_exito,
            'duracion' => $this->duracion_formateada,
            'iniciado_en' => $this->iniciado_en?->toIso8601String(),
            'completado_en' => $this->completado_en?->toIso8601String(),
        ];
    }
}