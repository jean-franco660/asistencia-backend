<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registra acciones de auditoría realizadas sobre el sistema.
 *
 * Cada entrada captura quién realizó la acción (actor), sobre qué modelo,
 * qué datos cambiaron (antes y después), y metadatos de la solicitud HTTP
 * (IP, user agent, URL). Soporta tres tipos de actores: usuario web,
 * usuario de la app y el sistema en procesos automáticos.
 *
 * Tabla: audit_logs
 * Relaciones principales: actor (polimórfica, UsuarioWeb o UsuarioApp)
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public const ACTOR_USUARIO_WEB = 'USUARIO_WEB';
    public const ACTOR_USUARIO_APP = 'USUARIO_APP';
    public const ACTOR_SISTEMA     = 'SISTEMA';

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
        'datos_nuevos' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Relación polimórfica con el actor que realizó la acción.
     * Puede ser un UsuarioWeb, un UsuarioApp o null cuando la acción fue del sistema.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Retorna un arreglo con los campos que cambiaron, mostrando el valor anterior y el nuevo.
     * Devuelve un arreglo vacío si no hay datos anteriores o nuevos registrados.
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
                    'nuevo' => $nuevo,
                ];
            }
        }

        return $cambios;
    }

    /**
     * Retorna una cadena legible que resume los campos modificados con sus valores anterior y nuevo.
     */
    public function getResumenCambiosAttribute(): string
    {
        $cambios = $this->cambios;

        if (empty($cambios)) {
            return 'Sin cambios detectados';
        }

        $resumen = [];
        foreach ($cambios as $campo => $valores) {
            $resumen[] = "{$campo}: '{$valores['anterior']}' '{$valores['nuevo']}'";
        }

        return implode(', ', $resumen);
    }

    /**
     * Retorna la fecha de creación del registro formateada como 'd/m/Y H:i:s'.
     */
    public function getFechaFormateadaAttribute(): string
    {
        return $this->created_at?->format('d/m/Y H:i:s') ?? '';
    }

    /**
     * Filtra registros por el identificador y tipo de actor.
     * Si `$actorType` es nulo, devuelve todos los registros del actor independientemente del tipo.
     */
    public function scopePorActor($query, $actorId, $actorType = null)
    {
        $query->where('actor_id', $actorId);
        
        if ($actorType) {
            $query->where('actor_type', $actorType);
        }
        
        return $query;
    }

    /**
     * Filtra registros por nombre de modelo y, opcionalmente, por su identificador.
     */
    public function scopePorModelo($query, $modelo, $modeloId = null)
    {
        $query->where('modelo', $modelo);
        
        if ($modeloId) {
            $query->where('modelo_id', $modeloId);
        }
        
        return $query;
    }

    /**
     * Filtra por un tipo de acción específico.
     */
    public function scopePorAccion($query, $accion)
    {
        return $query->where('accion', $accion);
    }

    /**
     * Filtra registros cuyo `created_at` cae dentro del rango indicado.
     */
    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('created_at', [$desde, $hasta]);
    }

    /**
     * Filtra acciones consideradas críticas: autorización, rechazo, eliminación,
     * importación y operaciones masivas.
     */
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

    /**
     * Filtra registros de actores tipo USUARIO_WEB.
     * Si se proporciona `$usuarioId`, restringe además al usuario específico.
     */
    public function scopePorUsuarioWeb($query, $usuarioId = null)
    {
        $query->where('actor_type', self::ACTOR_USUARIO_WEB);
        
        if ($usuarioId) {
            $query->where('actor_id', $usuarioId);
        }
        
        return $query;
    }

    /**
     * Filtra registros de actores tipo USUARIO_APP.
     * Si se proporciona `$usuarioId`, restringe además al usuario específico.
     */
    public function scopePorUsuarioApp($query, $usuarioId = null)
    {
        $query->where('actor_type', self::ACTOR_USUARIO_APP);
        
        if ($usuarioId) {
            $query->where('actor_id', $usuarioId);
        }
        
        return $query;
    }

    /**
     * Filtra registros generados por el sistema (sin actor humano).
     */
    public function scopePorSistema($query)
    {
        return $query->where('actor_type', self::ACTOR_SISTEMA);
    }

    /**
     * Retorna los registros más recientes, ordenados descendentemente, limitados a `$limite`.
     */
    public function scopeRecientes($query, int $limite = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limite);
    }

    /**
     * Indica si la acción registrada es de tipo crítico (autorización, rechazo, eliminación,
     * importación o masiva).
     */
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

    /**
     * Indica si la acción fue de creación de un registro.
     */
    public function esCreacion(): bool
    {
        return $this->accion === self::ACCION_CREATED;
    }

    /**
     * Indica si la acción fue de actualización de un registro.
     */
    public function esActualizacion(): bool
    {
        return $this->accion === self::ACCION_UPDATED;
    }

    /**
     * Indica si la acción fue de eliminación de un registro.
     */
    public function esEliminacion(): bool
    {
        return $this->accion === self::ACCION_DELETED;
    }

    /**
     * Indica si el registro cuenta con una dirección IP capturada.
     */
    public function tieneIpAddress(): bool
    {
        return !empty($this->ip_address);
    }

    /**
     * Registra una acción de auditoría en la base de datos.
     * Captura automáticamente la IP, user agent, URL y método HTTP del request actual.
     * Cuando `$actor` es null, el tipo se asigna como SISTEMA.
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
            'actor_id' => $actor?->id,
            'actor_type' => static::getActorType($actor),
            'actor_nombre' => static::getActorNombre($actor),
            'actor_rol' => static::getActorRol($actor),
            'accion' => $accion,
            'descripcion' => $descripcion,
            'modelo' => $modelo,
            'modelo_id' => $modeloId,
            'modelo_nombre' => $modeloNombre,
            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos' => $datosNuevos,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'metodo_http' => request()->method(),
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