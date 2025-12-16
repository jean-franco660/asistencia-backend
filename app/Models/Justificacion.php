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

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_APROBADO = 'APROBADO';
    public const ESTADO_RECHAZADO = 'RECHAZADO';

    protected $fillable = [
        'asistencia_id',
        'usuario_app_id',
        'institucion_id',
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

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'usuario_web_id');
    }

    public function getEstadoBadgeAttribute(): array
    {
        return match ($this->estado) {
            self::ESTADO_PENDIENTE => ['texto' => 'Pendiente', 'color' => 'warning'],
            self::ESTADO_APROBADO => ['texto' => 'Aprobado', 'color' => 'success'],
            self::ESTADO_RECHAZADO => ['texto' => 'Rechazado', 'color' => 'danger'],
            default => ['texto' => 'Desconocido', 'color' => 'dark'],
        };
    }

    public function getDiasAttribute(): int
    {
        return $this->fecha_inicio->diffInDays($this->fecha_fin) + 1;
    }

    // Scopes útiles
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

    public function scopePorDocente($query, $usuarioId)
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
        $fecha = $fecha ?? now()->toDateString();

        return $query->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->where('estado', self::ESTADO_APROBADO);
    }

    protected function getNombreAuditable(): string
    {
        return "Justificación #{$this->id} - {$this->tipo}";
    }
}
