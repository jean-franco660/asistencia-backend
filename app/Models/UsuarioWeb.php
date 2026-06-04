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

/**
 * Representa a los usuarios del panel web administrativo (super administradores, administradores y supervisores).
 *
 * Gestiona la autenticación mediante email y contraseña, el control de acceso por rol y estado,
 * la asignación de instituciones bajo supervisión, y la revisión de justificaciones.
 * Los nuevos usuarios quedan en estado «pendiente» hasta ser autorizados, excepto el super administrador,
 * que se autoriza automáticamente al crearse. Utiliza Sanctum para tokens de API y soft deletes.
 */
class UsuarioWeb extends Authenticatable
{
    use HasFactory, HasApiTokens, SoftDeletes, Notifiable, Auditable;

    protected $table = 'usuarios_web';



    public const ROL_SUPER_ADMIN = 'super_admin';
    public const ROL_ADMINISTRADOR = 'administrador';
    public const ROL_SUPERVISOR = 'supervisor';



    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_AUTORIZADO = 'autorizado';
    public const ESTADO_RECHAZADO = 'rechazado';



    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',
        'estado',
        'usuario_app_id', // Enlace con Usuario App
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


    /**
     * Aplica hash bcrypt a la contraseña antes de persistirla. No hace nada si el valor está vacío.
     */
    public function setPasswordAttribute($value): void
    {
        if (!empty($value)) {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    /**
     * Normaliza el email a minúsculas y sin espacios antes de persistirlo.
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    /**
     * Almacena el nombre con formato de título (primera letra de cada palabra en mayúscula).
     */
    public function setNombreAttribute($value): void
    {
        $this->attributes['nombre'] = ucwords(strtolower(trim($value)));
    }

    /**
     * Define los comportamientos automáticos del modelo al crear y al eliminar registros.
     *
     * Al crear: autoriza automáticamente a los super administradores.
     * Al eliminar: desvincula todas las instituciones asignadas al supervisor.
     */
    protected static function booted()
    {
        static::creating(function ($usuario) {
            if ($usuario->rol === self::ROL_SUPER_ADMIN) {
                $usuario->estado = self::ESTADO_AUTORIZADO;
            }
        });

        // Al eliminar (soft delete), desvincula instituciones para mantener la integridad referencial
        static::deleting(function ($usuario) {
            $usuario->instituciones()->detach();
        });
    }

    /**
     * Retorna el docente vinculado a este usuario web, si existe.
     */
    public function usuarioApp(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    /**
     * Retorna las instituciones asignadas al supervisor con fechas de inicio y fin de supervisión.
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
     * Retorna las justificaciones que este usuario web ha revisado o gestionado.
     */
    public function justificacionesRevisadas(): HasMany
    {
        return $this->hasMany(Justificacion::class, 'usuario_web_id');
    }

    /**
     * Retorna el historial de importaciones de datos realizadas por este usuario.
     */
    public function importaciones(): HasMany
    {
        return $this->hasMany(ImportacionLog::class, 'usuario_id');
    }

    /**
     * Retorna los registros de auditoría en los que este usuario fue el actor de la acción.
     */
    public function auditLogsComoActor(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id')
            ->where('actor_type', AuditLog::ACTOR_USUARIO_WEB);
    }

    /**
     * Filtra los usuarios con estado autorizado.
     */
    public function scopeAutorizados($query)
    {
        return $query->where('estado', self::ESTADO_AUTORIZADO);
    }

    /**
     * Filtra los usuarios con estado pendiente de autorización.
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    /**
     * Filtra los usuarios con estado rechazado.
     */
    public function scopeRechazados($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    /**
     * Filtra los usuarios por rol usando los valores de las constantes ROL_*.
     */
    public function scopePorRol($query, string $rol)
    {
        return $query->where('rol', $rol);
    }

    /**
     * Filtra los usuarios con rol de supervisor.
     */
    public function scopeSupervisores($query)
    {
        return $query->where('rol', self::ROL_SUPERVISOR);
    }

    /**
     * Filtra los usuarios con rol de administrador o super administrador.
     */
    public function scopeAdministradores($query)
    {
        return $query->whereIn('rol', [
            self::ROL_SUPER_ADMIN,
            self::ROL_ADMINISTRADOR,
        ]);
    }

    /**
     * Filtra los usuarios autorizados y no eliminados (lógicamente activos).
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', self::ESTADO_AUTORIZADO)
            ->whereNull('deleted_at');
    }

    /**
     * Filtra los usuarios cuyo nombre o email contengan el término indicado.
     */
    public function scopeBuscar($query, string $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombre', 'like', "%{$termino}%")
                ->orWhere('email', 'like', "%{$termino}%");
        });
    }

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

    /**
     * Verifica si el usuario puede acceder al sistema (autorizado y no eliminado).
     *
     * Combina el estado de autorización con la ausencia de soft delete.
     */
    public function puedeAcceder(): bool
    {
        return $this->estaAutorizado() && $this->deleted_at === null;
    }

    /**
     * Indica si el usuario tiene supervisoria sobre la institución indicada.
     *
     * Los super administradores y administradores tienen acceso a todas las instituciones.
     * Los supervisores solo acceden a las instituciones que les han sido asignadas.
     */
    public function tieneSupervisoria(int $institucionId): bool
    {
        if ($this->esSuperAdmin()) {
            return true;
        }

        if ($this->esAdministrador()) {
            return true;
        }

        // Los supervisores solo acceden a las instituciones que tienen asignadas
        return $this->instituciones()
            ->where('institucion_id', $institucionId)
            ->exists();
    }

    /**
     * Indica si el usuario puede gestionar justificaciones (solo administradores autorizados).
     */
    public function puedeGestionarJustificaciones(): bool
    {
        return $this->estaAutorizado() &&
            $this->esAdminOSuperAdmin();
    }

    /**
     * Indica si el usuario puede realizar importaciones masivas de datos.
     */
    public function puedeImportar(): bool
    {
        return $this->esAdminOSuperAdmin() && $this->estaAutorizado();
    }

    /**
     * Indica si el usuario puede gestionar otros usuarios del sistema.
     */
    public function puedeGestionarUsuarios(): bool
    {
        return $this->esSuperAdmin() || $this->esAdministrador();
    }

    /**
     * Indica si el usuario puede ver instituciones de toda la UGEL (no solo las asignadas).
     */
    public function puedeVerTodasInstituciones(): bool
    {
        return $this->esSuperAdmin() || $this->esAdministrador();
    }

    /**
     * Indica si el usuario puede editar la institución indicada.
     *
     * Los administradores pueden editar cualquier institución;
     * los supervisores solo las que tienen asignadas.
     */
    public function puedeEditarInstitucion(Institucion $institucion): bool
    {
        if ($this->esAdminOSuperAdmin()) {
            return true;
        }

        return $this->tieneSupervisoria($institucion->id);
    }

    /**
     * Indica si el usuario puede ver reportes globales de toda la UGEL.
     */
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
     * Retorna los IDs de las instituciones vigentes del supervisor.
     *
     * Si es administrador o super administrador, retorna un arreglo vacío como señal
     * de que tiene acceso irrestricto a todas las instituciones, evitando filtros innecesarios.
     */
    public function getInstitucionesVigentesIds(): array
    {
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

    /**
     * Retorna los roles disponibles para validación en formularios.
     */
    public static function getRolesDisponibles(): array
    {
        return [
            self::ROL_SUPER_ADMIN,
            self::ROL_ADMINISTRADOR,
            self::ROL_SUPERVISOR,
        ];
    }

    /**
     * Retorna los roles con sus etiquetas legibles para mostrar en selectores.
     */
    public static function getRolesConEtiquetas(): array
    {
        return [
            self::ROL_SUPER_ADMIN => 'Super Administrador',
            self::ROL_ADMINISTRADOR => 'Administrador',
            self::ROL_SUPERVISOR => 'Supervisor',
        ];
    }

    /**
     * Retorna los estados disponibles para validación en formularios.
     */
    public static function getEstadosDisponibles(): array
    {
        return [
            self::ESTADO_PENDIENTE,
            self::ESTADO_AUTORIZADO,
            self::ESTADO_RECHAZADO,
        ];
    }

    /**
     * Retorna los estados con sus etiquetas legibles para mostrar en selectores.
     */
    public static function getEstadosConEtiquetas(): array
    {
        return [
            self::ESTADO_PENDIENTE => 'Pendiente',
            self::ESTADO_AUTORIZADO => 'Autorizado',
            self::ESTADO_RECHAZADO => 'Rechazado',
        ];
    }

    /**
     * Retorna la representación textual del usuario para los registros de auditoría.
     */
    protected function getNombreAuditable(): string
    {
        return "{$this->nombre} ({$this->email})";
    }
}