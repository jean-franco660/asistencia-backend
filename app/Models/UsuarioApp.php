<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Representa al docente o personal que utiliza la aplicación móvil de asistencia.
 *
 * Gestiona la autenticación mediante código modular (en lugar del email estándar de Laravel),
 * las asignaciones institucionales, el historial de asistencias y las justificaciones.
 * El acceso puede habilitarse o deshabilitarse sin eliminar el registro.
 * Utiliza Sanctum para tokens de API y aplica soft deletes para preservar el historial.
 */
class UsuarioApp extends Authenticatable
{
    use HasFactory, HasApiTokens, Auditable, SoftDeletes;

    protected $table = 'usuarios_app';



    public const SEXO_MASCULINO = 'M';
    public const SEXO_FEMENINO = 'F';



    protected $fillable = [
        'codigo_modular',
        'dni',
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



    /**
     * Retorna el nombre completo en formato «Apellido Paterno Apellido Materno Nombres».
     */
    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->apellido_paterno} {$this->apellido_materno} {$this->nombres}");
    }

    /**
     * Retorna las iniciales del usuario (primera letra del apellido paterno, materno y primer nombre).
     * Devuelve 'N/A' si no puede construir ninguna inicial.
     */
    public function getInicialesAttribute(): string
    {
        $ap = mb_substr($this->apellido_paterno ?? '', 0, 1, 'UTF-8');
        $am = mb_substr($this->apellido_materno ?? '', 0, 1, 'UTF-8');

        $nombres = explode(' ', $this->nombres ?? '');
        $n = mb_substr($nombres[0] ?? '', 0, 1, 'UTF-8');

        return mb_strtoupper($ap . $am . $n, 'UTF-8') ?: 'N/A';
    }

    /**
     * Retorna la descripción legible del sexo ('Masculino', 'Femenino' o 'No especificado').
     */
    public function getSexoFormateadoAttribute(): string
    {
        return match ($this->sexo) {
            self::SEXO_MASCULINO => 'Masculino',
            self::SEXO_FEMENINO => 'Femenino',
            default => 'No especificado',
        };
    }

    /**
     * Asigna la contraseña del usuario aplicando hash bcrypt si el valor es texto plano.
     *
     * Detecta si el valor ya fue hasheado (longitud 60 y prefijo bcrypt) para evitar
     * doble hash en importaciones o migraciones de datos. No modifica el atributo
     * si el valor está vacío.
     */
    public function setPasswordAttribute($value): void
    {
        if (empty($value)) {
            return;
        }

        // Bcrypt produce exactamente 60 caracteres con prefijo $2y$, $2a$ o $2b$
        if (strlen($value) === 60 && preg_match('/^\$2[ayb]\$.{56}$/', $value)) {
            $this->attributes['password'] = $value;
        } else {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    /**
     * Almacena el apellido paterno en mayúsculas y sin espacios extremos.
     */
    public function setApellidoPaternoAttribute($value): void
    {
        $this->attributes['apellido_paterno'] = mb_strtoupper(trim($value), 'UTF-8');
    }

    /**
     * Almacena el apellido materno en mayúsculas y sin espacios extremos.
     */
    public function setApellidoMaternoAttribute($value): void
    {
        $this->attributes['apellido_materno'] = mb_strtoupper(trim($value), 'UTF-8');
    }

    /**
     * Almacena los nombres en mayúsculas y sin espacios extremos.
     */
    public function setNombresAttribute($value): void
    {
        $this->attributes['nombres'] = mb_strtoupper(trim($value), 'UTF-8');
    }

    /**
     * Almacena el código modular en mayúsculas y sin espacios extremos.
     */
    public function setCodigoModularAttribute($value): void
    {
        $this->attributes['codigo_modular'] = strtoupper(trim($value));
    }

    /**
     * Retorna el campo utilizado como identificador de autenticación.
     *
     * Sobrescribe el comportamiento por defecto de Laravel (email) para usar
     * el código modular como credencial de inicio de sesión.
     */
    public function getAuthIdentifierName(): string
    {
        return 'codigo_modular';
    }

    /**
     * Retorna el usuario web vinculado a este docente, si tiene acceso al panel administrativo.
     */
    public function usuarioWeb(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UsuarioWeb::class, 'usuario_app_id');
    }

    /**
     * Retorna todas las asignaciones institucionales del docente, en cualquier estado.
     */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(UsuarioAppInstitucion::class, 'usuario_app_id');
    }

    /**
     * Retorna solo las asignaciones institucionales con estado ACTIVO.
     */
    public function asignacionesActivas(): HasMany
    {
        return $this->asignaciones()->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
    }

    /**
     * Retorna todas las instituciones a las que el docente está o estuvo asignado,
     * con datos del pivote (horario, cargo, estado y fechas de vigencia).
     */
    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'usuario_app_institucion',
            'usuario_app_id',
            'institucion_id'
        )
            ->using(UsuarioAppInstitucion::class)
            ->withPivot([
                'horario_institucion_id',
                'cargo',
                'estado',
                'fecha_inicio',
                'fecha_fin',
            ])
            ->withTimestamps();
    }

    /**
     * Retorna solo las instituciones en las que el docente tiene estado ACTIVO en el pivote.
     */
    public function institucionesActivas(): BelongsToMany
    {
        return $this->instituciones()
            ->wherePivot('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
    }

    /**
     * Retorna el historial completo de asistencias del docente.
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'usuario_app_id');
    }

    /**
     * Retorna las justificaciones de inasistencia presentadas por el docente.
     */
    public function justificaciones(): HasMany
    {
        return $this->hasMany(Justificacion::class, 'usuario_app_id');
    }

    /**
     * Retorna los registros de auditoría en los que este docente fue el actor de la acción.
     */
    public function auditLogsComoActor(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id')
            ->where('actor_type', AuditLog::ACTOR_USUARIO_APP);
    }

    /**
     * Filtra los docentes que tienen el acceso a la app habilitado.
     */
    public function scopeConAcceso($query)
    {
        return $query->where('acceso_habilitado', true);
    }

    /**
     * Filtra los docentes que tienen el acceso a la app deshabilitado.
     */
    public function scopeSinAcceso($query)
    {
        return $query->where('acceso_habilitado', false);
    }

    /**
     * Filtra los docentes con acceso habilitado. Equivalente a scopeConAcceso.
     */
    public function scopeActivos($query)
    {
        return $query->where('acceso_habilitado', true);
    }

    /**
     * Filtra los docentes asignados a una institución, identificada por su ID numérico
     * o por su código modular (string). Solo considera asignaciones en estado ACTIVO.
     */
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

    /**
     * Filtra los docentes por sexo usando los valores de las constantes SEXO_MASCULINO / SEXO_FEMENINO.
     */
    public function scopePorSexo($query, string $sexo)
    {
        return $query->where('sexo', $sexo);
    }

    /**
     * Filtra los docentes cuyo nombre, apellidos o código modular contengan el término indicado.
     */
    public function scopeBuscar($query, string $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombres', 'like', "%{$termino}%")
                ->orWhere('apellido_paterno', 'like', "%{$termino}%")
                ->orWhere('apellido_materno', 'like', "%{$termino}%")
                ->orWhere('codigo_modular', 'like', "%{$termino}%");
        });
    }

    /**
     * Filtra los docentes que tienen al menos una asignación institucional en estado ACTIVO.
     */
    public function scopeConAsignacionActiva($query)
    {
        return $query->whereHas('asignaciones', function ($q) {
            $q->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
        });
    }

    /**
     * Filtra los docentes que tienen una asignación activa con el cargo indicado.
     */
    public function scopePorCargo($query, string $cargo)
    {
        return $query->whereHas('asignaciones', function ($q) use ($cargo) {
            $q->where('cargo', $cargo)
                ->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
        });
    }

    public function tieneAccesoHabilitado(): bool
    {
        return $this->acceso_habilitado === true;
    }

    /**
     * Verifica si el docente tiene al menos una asignación activa en la institución indicada.
     */
    public function tieneAsignacionEnInstitucion(int $institucionId): bool
    {
        return $this->asignacionesActivas()
            ->where('institucion_id', $institucionId)
            ->exists();
    }

    /**
     * Retorna la primera asignación activa del docente en la institución indicada.
     */
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
        return $this->asignacionesActivas()->exists();
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

    /**
     * Retorna los valores de sexo disponibles para validación en formularios.
     */
    public static function getSexosDisponibles(): array
    {
        return [
            self::SEXO_MASCULINO,
            self::SEXO_FEMENINO,
        ];
    }

    /**
     * Retorna los sexos con sus etiquetas legibles para mostrar en selectores.
     */
    public static function getSexosConEtiquetas(): array
    {
        return [
            self::SEXO_MASCULINO => 'Masculino',
            self::SEXO_FEMENINO => 'Femenino',
        ];
    }

    /**
     * Retorna la representación textual del docente para los registros de auditoría.
     */
    protected function getNombreAuditable(): string
    {
        return "{$this->nombre_completo} ({$this->codigo_modular})";
    }
}