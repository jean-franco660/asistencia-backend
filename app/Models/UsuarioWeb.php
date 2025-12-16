<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;

class UsuarioWeb extends Authenticatable
{
    use HasFactory, HasApiTokens, SoftDeletes, Auditable;

    // 🔹 Roles válidos (fuente de verdad)
    public const ROL_SUPER_ADMIN = 'super_admin';
    public const ROL_ADMIN = 'administrador';
    public const ROL_SUPERVISOR = 'supervisor';

    protected $table = 'usuarios_web';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',      // administrador o supervisor
        'estado',   // pendiente, autorizado o rechazado
    ];

    protected $hidden = ['password'];

    /**
     * Encripta automáticamente la contraseña antes de guardar.
     */
    public function setPasswordAttribute($value)
    {
        if (!empty($value)) {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    /**
     * Regla: si el rol es 'administrador', el estado siempre será 'autorizado'.
     */
    protected static function booted()
    {
        static::creating(function ($usuario) {
            if ($usuario->rol === 'administrador') {
                $usuario->estado = 'autorizado';
            }
        });

        static::deleting(function ($usuario) {
            // Al eliminar (soft delete), se desvinculan las instituciones asociadas
            $usuario->instituciones()->detach();
        });
    }

    /**
     * Relación N:M con instituciones a través de la tabla intermedia supervisor_institucion.
     * Un supervisor (anteriormente director) puede estar asociado a múltiples instituciones.
     */
    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'supervisor_institucion',
            'usuario_web_id',
            'institucion_id'
        )
            ->withPivot(['fecha_inicio', 'fecha_fin'])
            ->withTimestamps();
    }

    /**
     * Relación con feriados (si aplica).
     */
    public function feriados(): HasMany
    {
        return $this->hasMany(Feriado::class, 'usuario_id');
    }

    /**
     * Helper: obtener la institución actual del supervisor
     * considerando fechas de inicio y fin en la tabla pivote.
     */
    public function institucionActual()
    {
        $hoy = now()->toDateString();

        return $this->instituciones()
            ->wherePivot('fecha_inicio', '<=', $hoy)
            ->where(function ($q) use ($hoy) {
                $q->whereNull('supervisor_institucion.fecha_fin')
                    ->orWhere('supervisor_institucion.fecha_fin', '>=', $hoy);
            })
            ->first();
    }

    protected function getNombreAuditable(): string
    {
    return "{$this->nombre} ({$this->email})";
    }
}
