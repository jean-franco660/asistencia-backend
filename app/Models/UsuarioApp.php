<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Auditable;

class UsuarioApp extends Authenticatable
{
    use HasFactory, HasApiTokens, Auditable;

    protected $table = 'usuarios_app';

    /* =========================
     * CONSTANTES
     * ========================= */

    public const SEXO_MASCULINO = 'M';
    public const SEXO_FEMENINO  = 'F';

    /* =========================
     * FILLABLE / HIDDEN / CASTS
     * ========================= */

    protected $fillable = [
        'codigo_modular',
        'apellido_paterno',
        'apellido_materno',
        'nombres',
        'sexo',
        'acceso_habilitado',
        'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'acceso_habilitado' => 'boolean',
    ];

    protected $attributes = [
        'acceso_habilitado' => true,
    ];

    protected $appends = ['nombre_completo', 'iniciales'];

    /* =========================
     * ACCESSORS / MUTATORS
     * ========================= */

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->apellido_paterno} {$this->apellido_materno} {$this->nombres}");
    }

    public function getInicialesAttribute(): string
    {
        $nombres = explode(' ', $this->nombres);
        $inicial = substr($nombres[0] ?? '', 0, 1);
        
        return strtoupper(
            substr($this->apellido_paterno, 0, 1) . 
            substr($this->apellido_materno, 0, 1) . 
            $inicial
        );
    }

    public function getSexoFormateadoAttribute(): string
    {
        return match($this->sexo) {
            self::SEXO_MASCULINO => 'Masculino',
            self::SEXO_FEMENINO => 'Femenino',
            default => 'No especificado',
        };
    }

    public function setPasswordAttribute($value): void
    {
        if (!empty($value)) {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    public function setApellidoPaternoAttribute($value): void
    {
        $this->attributes['apellido_paterno'] = mb_strtoupper(trim($value));
    }

    public function setApellidoMaternoAttribute($value): void
    {
        $this->attributes['apellido_materno'] = mb_strtoupper(trim($value));
    }

    public function setNombresAttribute($value): void
    {
        $this->attributes['nombres'] = mb_strtoupper(trim($value));
    }

    public function setCodigoModularAttribute($value): void
    {
        $this->attributes['codigo_modular'] = strtoupper(trim($value));
    }

    /* =========================
     * AUTH (SANCTUM)
     * ========================= */

    public function getAuthIdentifierName(): string
    {
        return 'codigo_modular';
    }

    /* =========================
     * RELACIONES
     * ========================= */

    public function asignaciones(): HasMany
    {
        return $this->hasMany(UsuarioAppInstitucion::class, 'usuario_app_id');
    }

    public function asignacionesActivas(): HasMany
    {
        return $this->asignaciones()->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
    }

    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'usuario_app_institucion',
            'usuario_app_id',
            'institucion_id'
        )
        ->withPivot([
            'horario_institucion_id',
            'cargo',
            'estado',
            'fecha_inicio',
            'fecha_fin',
        ])
        ->withTimestamps();
    }

    public function institucionesActivas(): BelongsToMany
    {
        return $this->instituciones()
                    ->wherePivot('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'usuario_app_id');
    }

    public function justificaciones(): HasMany
    {
        return $this->hasMany(Justificacion::class, 'usuario_app_id');
    }

    public function auditLogsComoActor(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id')
                    ->where('actor_type', AuditLog::ACTOR_USUARIO_APP);
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopeConAcceso($query)
    {
        return $query->where('acceso_habilitado', true);
    }

    public function scopeSinAcceso($query)
    {
        return $query->where('acceso_habilitado', false);
    }

    public function scopeActivos($query)
    {
        return $query->where('acceso_habilitado', true);
    }

    public function scopePorInstitucion($query, $institucionIdOCodigo)
    {
        if (is_numeric($institucionIdOCodigo)) {
            return $query->whereHas('asignaciones', function ($q) use ($institucionIdOCodigo) {
                $q->where('institucion_id', $institucionIdOCodigo)
                  ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
            });
        }

        return $query->whereHas('asignaciones.institucion', function ($q) use ($institucionIdOCodigo) {
            $q->where('codigo_modular_ie', strtoupper(trim($institucionIdOCodigo)));
        })->whereHas('asignaciones', function ($q) {
            $q->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
        });
    }

    public function scopePorSexo($query, string $sexo)
    {
        return $query->where('sexo', $sexo);
    }

    public function scopeBuscar($query, string $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombres', 'like', "%{$termino}%")
              ->orWhere('apellido_paterno', 'like', "%{$termino}%")
              ->orWhere('apellido_materno', 'like', "%{$termino}%")
              ->orWhere('codigo_modular', 'like', "%{$termino}%");
        });
    }

    public function scopeConAsignacionActiva($query)
    {
        return $query->whereHas('asignaciones', function ($q) {
            $q->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
        });
    }

    public function scopePorCargo($query, string $cargo)
    {
        return $query->whereHas('asignaciones', function ($q) use ($cargo) {
            $q->where('cargo', $cargo)
              ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
        });
    }

    /* =========================
     * HELPERS
     * ========================= */

    public function tieneAccesoHabilitado(): bool
    {
        return $this->acceso_habilitado === true;
    }

    public function tieneAsignacionEnInstitucion(int $institucionId): bool
    {
        return $this->asignacionesActivas()
                    ->where('institucion_id', $institucionId)
                    ->exists();
    }

    public function getAsignacionActiva(int $institucionId): ?UsuarioAppInstitucion
    {
        return $this->asignacionesActivas()
                    ->where('institucion_id', $institucionId)
                    ->first();
    }

    /**
     * Obtiene todas las asignaciones vigentes (considerando fechas)
     */
    public function getAsignacionesVigentes()
    {
        $hoy = now()->toDateString();
        
        return $this->asignacionesActivas()
                    ->where(function ($q) use ($hoy) {
                        $q->whereNull('fecha_inicio')
                          ->orWhere('fecha_inicio', '<=', $hoy);
                    })
                    ->where(function ($q) use ($hoy) {
                        $q->whereNull('fecha_fin')
                          ->orWhere('fecha_fin', '>=', $hoy);
                    })
                    ->get();
    }

    /**
     * Obtiene IDs de instituciones donde tiene asignación vigente
     */
    public function getInstitucionesVigentesIds(): array
    {
        return $this->getAsignacionesVigentes()
                    ->pluck('institucion_id')
                    ->toArray();
    }

    /**
     * Verifica si tiene asignación vigente en alguna institución
     */
    public function tieneAsignacionVigente(): bool
    {
        return $this->getAsignacionesVigentes()->isNotEmpty();
    }

    /**
     * Habilita el acceso del usuario
     */
    public function habilitarAcceso(): bool
    {
        $this->acceso_habilitado = true;
        return $this->save();
    }

    /**
     * Deshabilita el acceso del usuario
     */
    public function deshabilitarAcceso(): bool
    {
        $this->acceso_habilitado = false;
        return $this->save();
    }

    /**
     * Obtiene el cargo principal (de la primera asignación vigente)
     */
    public function getCargoPrincipal(): ?string
    {
        return $this->getAsignacionesVigentes()->first()?->cargo;
    }

    /**
     * Obtiene la institución principal (primera asignación vigente)
     */
    public function getInstitucionPrincipal(): ?Institucion
    {
        $asignacion = $this->getAsignacionesVigentes()->first();
        return $asignacion?->institucion;
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    public static function getSexosDisponibles(): array
    {
        return [
            self::SEXO_MASCULINO,
            self::SEXO_FEMENINO,
        ];
    }

    public static function getSexosConEtiquetas(): array
    {
        return [
            self::SEXO_MASCULINO => 'Masculino',
            self::SEXO_FEMENINO  => 'Femenino',
        ];
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        return "{$this->nombre_completo} ({$this->codigo_modular})";
    }
}