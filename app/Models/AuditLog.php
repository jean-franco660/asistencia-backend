<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    /* =========================
     * CONSTANTES - TIPOS DE ACTOR
     * ========================= */

    public const ACTOR_USUARIO_WEB = 'USUARIO_WEB';
    public const ACTOR_USUARIO_APP = 'USUARIO_APP';
    public const ACTOR_SISTEMA     = 'SISTEMA';

    /* =========================
     * CONSTANTES - ACCIONES COMUNES
     * ========================= */

    public const ACCION_CREATED    = 'created';
    public const ACCION_UPDATED    = 'updated';
    public const ACCION_DELETED    = 'deleted';
    public const ACCION_RESTORED   = 'restored';
    public const ACCION_AUTORIZADO = 'autorizado';
    public const ACCION_RECHAZADO  = 'rechazado';
    public const ACCION_IMPORTADO  = 'importado';
    public const ACCION_EXPORTADO  = 'exportado';
    public const ACCION_MASIVO     = 'masivo';
    public const ACCION_LOGIN      = 'login';
    public const ACCION_LOGOUT     = 'logout';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_nombre',
        'actor_rol',
        'accion',
        'descripcion',
        'modelo',
        'modelo_id',
        'modelo_nombre',
        'datos_anteriores',
        'datos_nuevos',
        'metadata',
        'ip_address',
        'user_agent',
        'url',
        'metodo_http',
    ];

    protected $casts = [
        'datos_anteriores' => 'array',
        'datos_nuevos'     => 'array',
        'metadata'         => 'array',
    ];

    /* =========================
     * RELACIONES
     * ========================= */

    /**
     * Relación polimórfica con el actor (quien realizó la acción)
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    /**
     * Obtener cambios legibles
     */
    public function getCambiosAttribute(): array
    {
        if (!$this->datos_anteriores || !$this->datos_nuevos) {
            return [];
        }

        $cambios = [];
        foreach ($this->datos_nuevos as $campo => $nuevo) {
            $anterior = $this->datos_anteriores[$campo] ?? null;
            if ($anterior !== $nuevo) {
                $cambios[$campo] = [
                    'anterior' => $anterior,
                    'nuevo'    => $nuevo,
                ];
            }
        }

        return $cambios;
    }

    /**
     * Obtener resumen legible del cambio
     */
    public function getResumenCambiosAttribute(): string
    {
        $cambios = $this->cambios;
        
        if (empty($cambios)) {
            return 'Sin cambios detectados';
        }

        $resumen = [];
        foreach ($cambios as $campo => $valores) {
            $resumen[] = "{$campo}: '{$valores['anterior']}' → '{$valores['nuevo']}'";
        }

        return implode(', ', $resumen);
    }

    /**
     * Fecha formateada
     */
    public function getFechaFormateadaAttribute(): string
    {
        return $this->created_at?->format('d/m/Y H:i:s') ?? '';
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopePorActor($query, $actorId, $actorType = null)
    {
        $query->where('actor_id', $actorId);
        
        if ($actorType) {
            $query->where('actor_type', $actorType);
        }
        
        return $query;
    }

    public function scopePorModelo($query, $modelo, $modeloId = null)
    {
        $query->where('modelo', $modelo);
        
        if ($modeloId) {
            $query->where('modelo_id', $modeloId);
        }
        
        return $query;
    }

    public function scopePorAccion($query, $accion)
    {
        return $query->where('accion', $accion);
    }

    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('created_at', [$desde, $hasta]);
    }

    public function scopeAccionesCriticas($query)
    {
        return $query->whereIn('accion', [
            self::ACCION_AUTORIZADO,
            self::ACCION_RECHAZADO,
            self::ACCION_DELETED,
            self::ACCION_IMPORTADO,
            self::ACCION_MASIVO,
        ]);
    }

    public function scopePorUsuarioWeb($query, $usuarioId = null)
    {
        $query->where('actor_type', self::ACTOR_USUARIO_WEB);
        
        if ($usuarioId) {
            $query->where('actor_id', $usuarioId);
        }
        
        return $query;
    }

    public function scopePorUsuarioApp($query, $usuarioId = null)
    {
        $query->where('actor_type', self::ACTOR_USUARIO_APP);
        
        if ($usuarioId) {
            $query->where('actor_id', $usuarioId);
        }
        
        return $query;
    }

    public function scopePorSistema($query)
    {
        return $query->where('actor_type', self::ACTOR_SISTEMA);
    }

    public function scopeRecientes($query, int $limite = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limite);
    }

    /* =========================
     * HELPERS
     * ========================= */

    public function esAccionCritica(): bool
    {
        return in_array($this->accion, [
            self::ACCION_AUTORIZADO,
            self::ACCION_RECHAZADO,
            self::ACCION_DELETED,
            self::ACCION_IMPORTADO,
            self::ACCION_MASIVO,
        ], true);
    }

    public function esCreacion(): bool
    {
        return $this->accion === self::ACCION_CREATED;
    }

    public function esActualizacion(): bool
    {
        return $this->accion === self::ACCION_UPDATED;
    }

    public function esEliminacion(): bool
    {
        return $this->accion === self::ACCION_DELETED;
    }

    public function tieneIpAddress(): bool
    {
        return !empty($this->ip_address);
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    /**
     * Registra una acción de auditoría
     */
    public static function registrar(
        $actor,
        string $accion,
        string $modelo,
        $modeloId = null,
        ?string $modeloNombre = null,
        ?array $datosAnteriores = null,
        ?array $datosNuevos = null,
        ?string $descripcion = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'actor_id'         => $actor?->id,
            'actor_type'       => static::getActorType($actor),
            'actor_nombre'     => static::getActorNombre($actor),
            'actor_rol'        => static::getActorRol($actor),
            'accion'           => $accion,
            'descripcion'      => $descripcion,
            'modelo'           => $modelo,
            'modelo_id'        => $modeloId,
            'modelo_nombre'    => $modeloNombre,
            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos'     => $datosNuevos,
            'metadata'         => $metadata,
            'ip_address'       => request()->ip(),
            'user_agent'       => request()->userAgent(),
            'url'              => request()->fullUrl(),
            'metodo_http'      => request()->method(),
        ]);
    }

    /**
     * Obtiene el tipo de actor
     */
    protected static function getActorType($actor): ?string
    {
        if (!$actor) {
            return self::ACTOR_SISTEMA;
        }

        return match(true) {
            $actor instanceof UsuarioWeb => self::ACTOR_USUARIO_WEB,
            $actor instanceof UsuarioApp => self::ACTOR_USUARIO_APP,
            default => self::ACTOR_SISTEMA,
        };
    }

    /**
     * Obtiene el nombre del actor
     */
    protected static function getActorNombre($actor): ?string
    {
        if (!$actor) {
            return 'Sistema';
        }

        if ($actor instanceof UsuarioWeb) {
            return $actor->nombre;
        }

        if ($actor instanceof UsuarioApp) {
            return $actor->nombre_completo ?? ($actor->nombres . ' ' . $actor->apellido_paterno);
        }

        return 'Desconocido';
    }

    /**
     * Obtiene el rol del actor
     */
    protected static function getActorRol($actor): ?string
    {
        if (!$actor) {
            return null;
        }

        if ($actor instanceof UsuarioWeb) {
            return $actor->rol;
        }

        if ($actor instanceof UsuarioApp && isset($actor->cargo)) {
            return $actor->cargo;
        }

        return null;
    }
}