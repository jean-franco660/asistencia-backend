<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

/**
 * @property \Illuminate\Support\Carbon $fecha_inicio
 * @property \Illuminate\Support\Carbon $fecha_fin
 * @property \Illuminate\Support\Carbon|null $fecha_revision
 */
class Justificacion extends Model
{
    use HasFactory, Auditable;

    protected $table = 'justificaciones';

    /* =========================
     * CONSTANTES - TIPOS
     * ========================= */

    public const TIPO_ENFERMEDAD         = 'ENFERMEDAD';
    public const TIPO_PERMISO_PERSONAL   = 'PERMISO_PERSONAL';
    public const TIPO_LICENCIA           = 'LICENCIA';
    public const TIPO_COMISION_SERVICIO  = 'COMISION_SERVICIO';
    public const TIPO_CAPACITACION       = 'CAPACITACION';
    public const TIPO_DUELO              = 'DUELO';
    public const TIPO_MATERNIDAD         = 'MATERNIDAD';
    public const TIPO_PATERNIDAD         = 'PATERNIDAD';
    public const TIPO_OLVIDO_MARCACION   = 'OLVIDO_MARCACION';
    public const TIPO_OTRO               = 'OTRO';

    /* =========================
     * CONSTANTES - ESTADOS
     * ========================= */

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_APROBADO  = 'APROBADO';
    public const ESTADO_RECHAZADO = 'RECHAZADO';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

    protected $fillable = [
        'asistencia_id',
        'usuario_app_id',
        'institucion_id',
        'horario_institucion_id',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'estado',
        'usuario_web_id',
        'observaciones',
        'fecha_revision',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_revision' => 'datetime',
    ];

    protected $attributes = [
        'estado' => self::ESTADO_PENDIENTE,
    ];

    /* =========================
     * EVENTOS DEL MODELO
     * ========================= */

    protected static function boot()
    {
        parent::boot();

        // NUEVO: Validación de fechas
        static::saving(function ($model) {
            // Validar que fecha_fin >= fecha_inicio
            if ($model->fecha_fin && $model->fecha_inicio) {
                if ($model->fecha_fin->isBefore($model->fecha_inicio)) {
                    throw new \InvalidArgumentException(
                        'La fecha fin debe ser posterior o igual a la fecha inicio'
                    );
                }
            }
        });
    }

    /* =========================
     * RELACIONES
     * ========================= */

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    public function usuarioApp(): BelongsTo
    {
        return $this->usuario(); // Alias
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(HorarioInstitucion::class, 'horario_institucion_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'usuario_web_id');
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getEstadoBadgeAttribute(): array
    {
        return match ($this->estado) {
            self::ESTADO_PENDIENTE => ['texto' => 'Pendiente', 'color' => 'warning'],
            self::ESTADO_APROBADO  => ['texto' => 'Aprobado',   'color' => 'success'],
            self::ESTADO_RECHAZADO => ['texto' => 'Rechazado',  'color' => 'danger'],
            default => ['texto' => 'Desconocido', 'color' => 'dark'],
        };
    }

    public function getDiasAttribute(): int
    {
        if (!$this->fecha_inicio || !$this->fecha_fin) {
            return 0;
        }
        return $this->fecha_inicio->diffInDays($this->fecha_fin) + 1;
    }

    public function getTipoFaltaAttribute(): string
    {
        if (!$this->asistencia_id) {
            return 'FALTA_COMPLETA';
        }

        $asistencia = $this->asistencia;
        
        if (!$asistencia) {
            return 'DESCONOCIDO';
        }
        
        if ($asistencia->tipo === Asistencia::TIPO_ENTRADA) {
            return 'FALTA_SALIDA';
        }
        
        if ($asistencia->tipo === Asistencia::TIPO_SALIDA) {
            return 'FALTA_ENTRADA';
        }

        if ($asistencia->resultado === Asistencia::RESULTADO_TARDE) {
            return 'TARDANZA';
        }

        if ($asistencia->resultado === Asistencia::RESULTADO_SALIDA_ANTES) {
            return 'SALIDA_ANTICIPADA';
        }

        return 'OTRO';
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopeAprobadas($query)
    {
        return $query->where('estado', self::ESTADO_APROBADO);
    }

    public function scopeRechazadas($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    public function scopePorUsuarioApp($query, $usuarioId)
    {
        return $query->where('usuario_app_id', $usuarioId);
    }

    public function scopePorInstitucion($query, $institucionId)
    {
        return $query->where('institucion_id', $institucionId);
    }

    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeVigentes($query, $fecha = null)
    {
        $fecha = $fecha ? \Carbon\Carbon::parse($fecha) : today();

        return $query->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->where('estado', self::ESTADO_APROBADO);
    }

    public function scopePorOlvido($query)
    {
        return $query->where('tipo', self::TIPO_OLVIDO_MARCACION);
    }

    public function scopeFaltasCompletas($query)
    {
        return $query->whereNull('asistencia_id');
    }

    public function scopeFaltasParciales($query)
    {
        return $query->whereNotNull('asistencia_id');
    }

    public function scopeSinRevisar($query)
    {
        return $query->whereNull('usuario_web_id')
                     ->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopePorRevisor($query, $usuarioWebId)
    {
        return $query->where('usuario_web_id', $usuarioWebId);
    }

    public function scopeRevisadas($query)
    {
        return $query->whereNotNull('usuario_web_id')
                    ->whereNotNull('fecha_revision');
    }

    public function scopeEntreFechas($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('fecha_inicio', [$fechaInicio, $fechaFin])
                    ->orWhereBetween('fecha_fin', [$fechaInicio, $fechaFin]);
    }
    /* =========================
     * HELPERS DE ESTADO
     * ========================= */

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    public function estaAprobada(): bool
    {
        return $this->estado === self::ESTADO_APROBADO;
    }

    public function estaRechazada(): bool
    {
        return $this->estado === self::ESTADO_RECHAZADO;
    }

    public function fueRevisada(): bool
    {
        return $this->usuario_web_id !== null;
    }

    public function esFaltaCompleta(): bool
    {
        return $this->asistencia_id === null;
    }

    public function esFaltaParcial(): bool
    {
        return $this->asistencia_id !== null;
    }

    public function esOlvidoMarcacion(): bool
    {
        return $this->tipo === self::TIPO_OLVIDO_MARCACION;
    }

    /* =========================
     * MÉTODOS DE NEGOCIO
     * ========================= */

    public function aprobar(UsuarioWeb $revisor, ?string $observaciones = null): bool
    {
        \DB::beginTransaction();
        
        try {
            $this->estado = self::ESTADO_APROBADO;
            $this->usuario_web_id = $revisor->id;
            $this->observaciones = $observaciones;
            $this->fecha_revision = now();
            $this->save();

            if ($this->asistencia_id) {
                $asistencia = $this->asistencia;
                $asistencia->situacion = Asistencia::SITUACION_JUSTIFICADO;
                $asistencia->save();
            }

            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error("Error al aprobar justificación {$this->id}: {$e->getMessage()}");
            return false;
        }
    }

    public function rechazar(UsuarioWeb $revisor, string $observaciones): bool
    {
        \DB::beginTransaction();
        
        try {
            $this->estado = self::ESTADO_RECHAZADO;
            $this->usuario_web_id = $revisor->id;
            $this->observaciones = $observaciones;
            $this->fecha_revision = now();
            $this->save();

            if ($this->asistencia_id) {
                $asistencia = $this->asistencia;
                $asistencia->situacion = Asistencia::SITUACION_FALTA;
                $asistencia->save();
            }

            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error("Error al rechazar justificación {$this->id}: {$e->getMessage()}");
            return false;
        }
    }

    public function cubreFecha(\Carbon\Carbon $fecha): bool
    {
        return $fecha->between($this->fecha_inicio, $this->fecha_fin, true); // true = inclusivo
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    public static function getTiposDisponibles(): array
    {
        return [
            self::TIPO_ENFERMEDAD,
            self::TIPO_PERMISO_PERSONAL,
            self::TIPO_LICENCIA,
            self::TIPO_COMISION_SERVICIO,
            self::TIPO_CAPACITACION,
            self::TIPO_DUELO,
            self::TIPO_MATERNIDAD,
            self::TIPO_PATERNIDAD,
            self::TIPO_OLVIDO_MARCACION,
            self::TIPO_OTRO,
        ];
    }

    public static function getTiposConEtiquetas(): array
    {
        return [
            self::TIPO_ENFERMEDAD         => 'Enfermedad',
            self::TIPO_PERMISO_PERSONAL   => 'Permiso Personal',
            self::TIPO_LICENCIA           => 'Licencia',
            self::TIPO_COMISION_SERVICIO  => 'Comisión de Servicio',
            self::TIPO_CAPACITACION       => 'Capacitación',
            self::TIPO_DUELO              => 'Duelo/Luto',
            self::TIPO_MATERNIDAD         => 'Maternidad',
            self::TIPO_PATERNIDAD         => 'Paternidad',
            self::TIPO_OLVIDO_MARCACION   => 'Olvido de Marcación',
            self::TIPO_OTRO               => 'Otro',
        ];
    }

    /**
     * Crea una justificación de falta completa
    */
    public static function crearPorFaltaCompleta(
        UsuarioApp $usuario,
        Institucion $institucion,
        string $tipo,
        \Carbon\Carbon $fechaInicio,
        \Carbon\Carbon $fechaFin,
        string $motivo,
        ?int $horarioId = null
    ): self {
        return static::create([
            'asistencia_id' => null,
            'usuario_app_id' => $usuario->id,
            'institucion_id' => $institucion->id,
            'horario_institucion_id' => $horarioId,
            'tipo' => $tipo,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'motivo' => $motivo,
        ]);
    }

    /**
     * Crea una justificación de falta parcial (vinculada a asistencia)
    */
    public static function crearPorFaltaParcial(
        Asistencia $asistencia,
        string $tipo,
        string $motivo
    ): self {
        return static::create([
            'asistencia_id' => $asistencia->id,
            'usuario_app_id' => $asistencia->usuario_app_id,
            'institucion_id' => $asistencia->institucion_id,
            'horario_institucion_id' => $asistencia->horario_institucion_id,
            'tipo' => $tipo,
            'fecha_inicio' => $asistencia->fecha,
            'fecha_fin' => $asistencia->fecha,
            'motivo' => $motivo,
        ]);
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        return "Justificación #{$this->id} - {$this->tipo}";
    }

    
}