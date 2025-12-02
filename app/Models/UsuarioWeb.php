<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\SoftDeletes;

class UsuarioWeb extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'usuarios_web';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',      // admin o director
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
     * 🔒 Regla: si el rol es 'admin', el estado siempre será 'autorizado'.
     */
    protected static function booted()
    {
        static::creating(function ($usuario) {
            if ($usuario->rol === 'admin') {
                $usuario->estado = 'autorizado';
            }
        });

        static::deleting(function ($usuario) {
            $usuario->instituciones()->detach();
        });
    }

    /**
     * Relación N:M con instituciones a través de la tabla intermedia director_institucion.
     */
    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'director_institucion',
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
     * Helper: obtener la institución actual del director considerando fechas de inicio y fin.
     */
    public function institucionActual()
    {
        $hoy = now()->toDateString();

        return $this->instituciones()
            ->wherePivot('fecha_inicio', '<=', $hoy)
            ->where(function ($q) use ($hoy) {
                $q->whereNull('director_institucion.fecha_fin')
                    ->orWhere('director_institucion.fecha_fin', '>=', $hoy);
            })
            ->first();
    }
}