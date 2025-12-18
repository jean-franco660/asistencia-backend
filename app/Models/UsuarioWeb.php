<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Traits\Auditable;

class UsuarioWeb extends Authenticatable
{
    use HasFactory, HasApiTokens, SoftDeletes, Notifiable, Auditable;

    protected $table = 'usuarios_web';

    /* =========================
     * CONSTANTES - ROLES
     * ========================= */

    public const ROL_SUPER_ADMIN = 'super_admin';
    public const ROL_ADMINISTRADOR = 'administrador';
    public const ROL_SUPERVISOR = 'supervisor';

    /* =========================
     * CONSTANTES - ESTADOS
     * ========================= */

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_AUTORIZADO = 'autorizado';
    public const ESTADO_RECHAZADO = 'rechazado';

    /* =========================
     * FILLABLE / HIDDEN / CASTS
     * ========================= */

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',
        'estado',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $attributes = [
        'rol' => self::ROL_SUPERVISOR,
        'estado' => self::ESTADO_PENDIENTE,
    ];

    /* =========================
     * MUTATORS
     * ========================= */

    public function setPasswordAttribute($value): void
    {
        if (!empty($value)) {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    public function setNombreAttribute($value): void
    {
        $this->attributes['nombre'] = ucwords(strtolower(trim($value)));
    }

    /* =========================
     * EVENTOS DEL MODELO
     * ========================= */

    protected static function booted()
    {
        // Auto-autorizar SOLO a super_admin
        static::creating(function ($usuario) {
            if ($usuario->rol === self::ROL_SUPER_ADMIN) {
                $usuario->estado = self::ESTADO_AUTORIZADO;
            }
        });

        // Al eliminar (soft delete), desvincula instituciones
        static::deleting(function ($usuario) {
            $usuario->instituciones()->detach();
        });
    }

    /* =========================
     * RELACIONES
     * ========================= */

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

    public function justificacionesRevisadas(): HasMany
    {
        return $this->hasMany(Justificacion::class, 'usuario_web_id');
    }

    public function importaciones(): HasMany
    {
        return $this->hasMany(ImportacionLog::class, 'usuario_id');
    }

    public function auditLogsComoActor(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id')
            ->where('actor_type', AuditLog::ACTOR_USUARIO_WEB);
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopeAutorizados($query)
    {
        return $query->where('estado', self::ESTADO_AUTORIZADO);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopeRechazados($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    public function scopePorRol($query, string $rol)
    {
        return $query->where('rol', $rol);
    }

    public function scopeSupervisores($query)
    {
        return $query->where('rol', self::ROL_SUPERVISOR);
    }

    public function scopeAdministradores($query)
    {
        return $query->whereIn('rol', [
            self::ROL_SUPER_ADMIN,
            self::ROL_ADMINISTRADOR,
        ]);
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', self::ESTADO_AUTORIZADO)
            ->whereNull('deleted_at');
    }

    public function scopeBuscar($query, string $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombre', 'like', "%{$termino}%")
                ->orWhere('email', 'like', "%{$termino}%");
        });
    }

    /* =========================
     * HELPERS DE ROL
     * ========================= */

    public function esSuperAdmin(): bool
    {
        return $this->rol === self::ROL_SUPER_ADMIN;
    }

    public function esAdministrador(): bool
    {
        return $this->rol === self::ROL_ADMINISTRADOR;
    }

    public function esSupervisor(): bool
    {
        return $this->rol === self::ROL_SUPERVISOR;
    }

    public function esAdminOSuperAdmin(): bool
    {
        return in_array($this->rol, [
            self::ROL_SUPER_ADMIN,
            self::ROL_ADMINISTRADOR,
        ], true);
    }

    /* =========================
     * HELPERS DE ESTADO
     * ========================= */

    public function estaAutorizado(): bool
    {
        return $this->estado === self::ESTADO_AUTORIZADO;
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    public function estaRechazado(): bool
    {
        return $this->estado === self::ESTADO_RECHAZADO;
    }

    public function estaActivo(): bool
    {
        return $this->estaAutorizado() && !$this->trashed();
    }

    /* =========================
     * HELPERS DE PERMISOS
     * ========================= */

    public function puedeAcceder(): bool
    {
        return $this->estaAutorizado() && $this->deleted_at === null;
    }

    public function tieneSupervisoria(int $institucionId): bool
    {
        // Super admin tiene acceso a todo
        if ($this->esSuperAdmin()) {
            return true;
        }

        // Administradores tienen acceso a todo
        if ($this->esAdministrador()) {
            return true;
        }

        // Supervisores solo a sus instituciones asignadas
        return $this->instituciones()
            ->where('institucion_id', $institucionId)
            ->exists();
    }

    public function puedeGestionarJustificaciones(): bool
    {
        return $this->estaAutorizado() &&
            $this->esAdminOSuperAdmin();
    }

    public function puedeImportar(): bool
    {
        return $this->esAdminOSuperAdmin() && $this->estaAutorizado();
    }

    public function puedeGestionarUsuarios(): bool
    {
        return $this->esSuperAdmin() || $this->esAdministrador();
    }

    public function puedeVerTodasInstituciones(): bool
    {
        return $this->esSuperAdmin() || $this->esAdministrador();
    }

    public function puedeEditarInstitucion(Institucion $institucion): bool
    {
        // Super admin y admin pueden editar cualquier institución
        if ($this->esAdminOSuperAdmin()) {
            return true;
        }

        // Supervisores solo pueden editar sus instituciones asignadas
        return $this->tieneSupervisoria($institucion->id);
    }

    public function puedeVerReportesGlobales(): bool
    {
        return $this->esAdminOSuperAdmin();
    }

    /* =========================
     * MÉTODOS DE NEGOCIO
     * ========================= */

    /**
     * Obtiene la institución actual del supervisor
     * considerando fechas de inicio y fin en la tabla pivote.
     */
    public function institucionActual(): ?Institucion
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

    /**
     * Obtiene todas las instituciones vigentes
     */
    public function institucionesVigentes()
    {
        $hoy = now()->toDateString();

        return $this->instituciones()
            ->where(function ($q) use ($hoy) {
                $q->whereNull('supervisor_institucion.fecha_inicio')
                    ->orWhere('supervisor_institucion.fecha_inicio', '<=', $hoy);
            })
            ->where(function ($q) use ($hoy) {
                $q->whereNull('supervisor_institucion.fecha_fin')
                    ->orWhere('supervisor_institucion.fecha_fin', '>=', $hoy);
            })
            ->get();
    }

    /**
     * Obtiene IDs de instituciones vigentes (útil para queries)
     */
    public function getInstitucionesVigentesIds(): array
    {
        // Si es admin o super_admin, retorna array vacío (tiene acceso a todo)
        if ($this->esAdminOSuperAdmin()) {
            return [];
        }

        return $this->institucionesVigentes()->pluck('id')->toArray();
    }

    /**
     * Autoriza al usuario
     */
    public function autorizar(): bool
    {
        $this->estado = self::ESTADO_AUTORIZADO;
        return $this->save();
    }

    /**
     * Rechaza al usuario
     */
    public function rechazar(): bool
    {
        $this->estado = self::ESTADO_RECHAZADO;
        return $this->save();
    }

    /**
     * Asigna una institución al supervisor
     */
    public function asignarInstitucion(
        int $institucionId,
        ?\Carbon\Carbon $fechaInicio = null,
        ?\Carbon\Carbon $fechaFin = null
    ): void {
        $this->instituciones()->attach($institucionId, [
            'fecha_inicio' => $fechaInicio ?? now(),
            'fecha_fin' => $fechaFin,
        ]);
    }

    /**
     * Desvincula una institución del supervisor
     */
    public function desvincularInstitucion(int $institucionId): void
    {
        $this->instituciones()->detach($institucionId);
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    public static function getRolesDisponibles(): array
    {
        return [
            self::ROL_SUPER_ADMIN,
            self::ROL_ADMINISTRADOR,
            self::ROL_SUPERVISOR,
        ];
    }

    public static function getRolesConEtiquetas(): array
    {
        return [
            self::ROL_SUPER_ADMIN => 'Super Administrador',
            self::ROL_ADMINISTRADOR => 'Administrador',
            self::ROL_SUPERVISOR => 'Supervisor',
        ];
    }

    public static function getEstadosDisponibles(): array
    {
        return [
            self::ESTADO_PENDIENTE,
            self::ESTADO_AUTORIZADO,
            self::ESTADO_RECHAZADO,
        ];
    }

    public static function getEstadosConEtiquetas(): array
    {
        return [
            self::ESTADO_PENDIENTE => 'Pendiente',
            self::ESTADO_AUTORIZADO => 'Autorizado',
            self::ESTADO_RECHAZADO => 'Rechazado',
        ];
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        return "{$this->nombre} ({$this->email})";
    }
}