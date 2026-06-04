<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportacionLog extends Model
{
    use HasFactory;

    protected $table = 'importaciones_log';

    /* =========================
     * CONSTANTES - ESTADOS
     * ========================= */

    public const ESTADO_PENDING    = 'pending';
    public const ESTADO_PROCESSING = 'processing';
    public const ESTADO_COMPLETED  = 'completed';
    public const ESTADO_FAILED     = 'failed';

    /* =========================
     * CONSTANTES - TIPOS
     * ========================= */

    public const TIPO_INSTITUCIONES = 'instituciones';
    public const TIPO_USUARIOS_APP  = 'usuarios_app';
    public const TIPO_ASIGNACIONES  = 'asignaciones';
    public const TIPO_ASISTENCIAS   = 'asistencias';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

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
        'errores_archivo',
        'iniciado_en',
        'completado_en',
    ];

    protected $casts = [
        'total' => 'integer',
        'procesados' => 'integer',
        'exitosos' => 'integer',
        'errores_count' => 'integer',
        'errores_detalle' => 'array',
        'iniciado_en' => 'datetime',
        'completado_en' => 'datetime',
    ];

    protected $attributes = [
        'estado' => self::ESTADO_PENDING,
        'total' => 0,
        'procesados' => 0,
        'exitosos' => 0,
        'errores_count' => 0,
    ];

    /* =========================
     * RELACIONES
     * ========================= */

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'usuario_id');
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopeTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopeCompletadas($query)
    {
        return $query->where('estado', self::ESTADO_COMPLETED);
    }

    public function scopeEnProgreso($query)
    {
        return $query->whereIn('estado', [
            self::ESTADO_PENDING,
            self::ESTADO_PROCESSING,
        ]);
    }

    public function scopeFallidas($query)
    {
        return $query->where('estado', self::ESTADO_FAILED);
    }

    public function scopePorUsuario($query, int $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopeRecientes($query, int $limite = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limite);
    }

    public function scopeDelDia($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeDelMes($query)
    {
        return $query->whereYear('created_at', now()->year)
                     ->whereMonth('created_at', now()->month);
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    /**
     * Porcentaje de avance
     */
    public function getPorcentajeAttribute(): int
    {
        if (($this->total ?? 0) === 0) {
            return 0;
        }

        return (int) round((($this->procesados ?? 0) / $this->total) * 100);
    }

    /**
     * Duración en segundos
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
     * Duración legible
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

        $minutos = intdiv($duracion, 60);
        $segundos = $duracion % 60;

        if ($minutos < 60) {
            return "{$minutos} min, {$segundos} seg";
        }

        $horas = intdiv($minutos, 60);
        $minRest = $minutos % 60;

        return "{$horas}h {$minRest}min";
    }

    /**
     * Tasa de éxito (%)
     */
    public function getTasaExitoAttribute(): float
    {
        if (($this->procesados ?? 0) === 0) {
            return 0.0;
        }

        return round((($this->exitosos ?? 0) / $this->procesados) * 100, 2);
    }

    /**
     * Estado formateado
     */
    public function getEstadoFormateadoAttribute(): string
    {
        return match($this->estado) {
            self::ESTADO_PENDING    => 'Pendiente',
            self::ESTADO_PROCESSING => 'Procesando',
            self::ESTADO_COMPLETED  => 'Completado',
            self::ESTADO_FAILED     => 'Fallido',
            default => 'Desconocido',
        };
    }

    public function scopeRecientesCompletadas($query, int $limite = 10)
    {
        return $query
            ->where('estado', self::ESTADO_COMPLETED)
            ->orderByDesc('completado_en')
            ->orderByDesc('id')
            ->limit($limite);
    }


    /**
     * Tipo formateado
     */
    public function getTipoFormateadoAttribute(): string
    {
        return match($this->tipo) {
            self::TIPO_INSTITUCIONES => 'Instituciones',
            self::TIPO_USUARIOS_APP  => 'Usuarios',
            self::TIPO_ASIGNACIONES  => 'Asignaciones',
            self::TIPO_ASISTENCIAS   => 'Asistencias',
            default => ucfirst($this->tipo),
        };
    }

    /**
     * Resumen compacto para API / dashboard
     */
    public function getResumenAttribute(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'tipo_texto' => $this->tipo_formateado,
            'estado' => $this->estado,
            'estado_texto' => $this->estado_formateado,
            'total' => $this->total,
            'procesados' => $this->procesados,
            'exitosos' => $this->exitosos,
            'errores' => $this->errores_count,
            'porcentaje' => $this->porcentaje,
            'tasa_exito' => $this->tasa_exito,
            'duracion' => $this->duracion_formateada,
            'usuario' => $this->usuario?->nombre,
            'iniciado_en' => $this->iniciado_en?->toIso8601String(),
            'completado_en' => $this->completado_en?->toIso8601String(),
        ];
    }

    /* =========================
     * HELPERS DE ESTADO
     * ========================= */

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDING;
    }

    public function estaProcesando(): bool
    {
        return $this->estado === self::ESTADO_PROCESSING;
    }

    public function estaCompletada(): bool
    {
        return $this->estado === self::ESTADO_COMPLETED;
    }

    public function estaEnProgreso(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_PENDING,
            self::ESTADO_PROCESSING,
        ], true);
    }

    public function fallo(): bool
    {
        return $this->estado === self::ESTADO_FAILED;
    }

    public function tieneErrores(): bool
    {
        return ($this->errores_count ?? 0) > 0;
    }

    public function completoSinErrores(): bool
    {
        return $this->estaCompletada() && !$this->tieneErrores();
    }

    /* =========================
     * MÉTODOS DE NEGOCIO
     * ========================= */

    /**
     * Inicia el procesamiento de la importación
     */
    public function marcarComoProcesando(): bool
    {
        $this->estado = self::ESTADO_PROCESSING;
        $this->iniciado_en = now();
        return $this->save();
    }

    /**
     * Marca la importación como completada
     */
    public function marcarComoCompletada(): bool
    {
        $this->estado = self::ESTADO_COMPLETED;
        $this->completado_en = now();
        return $this->save();
    }

    /**
     * Marca la importación como fallida
     */
    public function marcarComoFallida(string $error): bool
    {
        $this->estado = self::ESTADO_FAILED;
        $this->completado_en = now();
        $this->agregarError(['mensaje' => $error, 'fatal' => true]);
        return $this->save();
    }

    /**
     * Incrementa el contador de procesados
     */
    public function incrementarProcesados(): bool
    {
        $this->procesados++;
        return $this->save();
    }

    /**
     * Incrementa el contador de exitosos
     */
    public function incrementarExitosos(): bool
    {
        $this->exitosos++;
        return $this->save();
    }

    /**
     * Agrega un error al detalle
     */
    public function agregarError(array $error): bool
    {
        $errores = $this->errores_detalle ?? [];
        $errores[] = array_merge($error, ['timestamp' => now()->toIso8601String()]);
        
        $this->errores_detalle = $errores;
        $this->errores_count++;
        
        return $this->save();
    }

    /**
     * Actualiza el total de registros
     */
    public function actualizarTotal(int $total): bool
    {
        $this->total = $total;
        return $this->save();
    }

    /**
     * Actualiza el progreso en batch
     */
    public function actualizarProgreso(int $procesados, int $exitosos, int $errores = 0): bool
    {
        $this->procesados = $procesados;
        $this->exitosos = $exitosos;
        $this->errores_count = $errores;
        
        return $this->save();
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    public static function getTiposDisponibles(): array
    {
        return [
            self::TIPO_INSTITUCIONES,
            self::TIPO_USUARIOS_APP,
            self::TIPO_ASIGNACIONES,
            self::TIPO_ASISTENCIAS,
        ];
    }

    public static function getTiposConEtiquetas(): array
    {
        return [
            self::TIPO_INSTITUCIONES => 'Instituciones',
            self::TIPO_USUARIOS_APP  => 'Usuarios de la App',
            self::TIPO_ASIGNACIONES  => 'Asignaciones',
            self::TIPO_ASISTENCIAS   => 'Asistencias',
        ];
    }

    public static function getEstadosDisponibles(): array
    {
        return [
            self::ESTADO_PENDING,
            self::ESTADO_PROCESSING,
            self::ESTADO_COMPLETED,
            self::ESTADO_FAILED,
        ];
    }

    public static function getEstadosConEtiquetas(): array
    {
        return [
            self::ESTADO_PENDING    => 'Pendiente',
            self::ESTADO_PROCESSING => 'Procesando',
            self::ESTADO_COMPLETED  => 'Completado',
            self::ESTADO_FAILED     => 'Fallido',
        ];
    }

    /**
     * Crea una nueva importación
     */
    public static function crear(
        UsuarioWeb $usuario,
        string $tipo,
        string $archivoOriginal,
        string $archivoTemp
    ): self {
        return static::create([
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'archivo_original' => $archivoOriginal,
            'archivo_temp' => $archivoTemp,
            'estado' => self::ESTADO_PENDING,
        ]);
    }

    /**
     * Limpia importaciones antiguas completadas
     */
    public static function limpiarAntiguas(int $diasAtras = 30): int
    {
        return static::where('estado', self::ESTADO_COMPLETED)
                     ->where('completado_en', '<', now()->subDays($diasAtras))
                     ->delete();
    }
}