<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Models\UsuarioWeb;
use App\Models\UsuarioApp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    /**
     * Boot del trait - registra eventos del modelo
     */
    protected static function bootAuditable()
    {
        // Al crear
        static::created(function ($model) {
            $model->auditarAccion('created', 'Registro creado');
        });

        // Al actualizar
        static::updated(function ($model) {
            // Solo auditar si hay cambios reales en campos auditables
            if ($model->hasAuditableChanges()) {
                $model->auditarAccion('updated', 'Registro actualizado');
            }
        });

        // Al eliminar (soft delete o hard delete)
        static::deleted(function ($model) {
            $accion = method_exists($model, 'trashed') && $model->trashed() 
                ? 'soft_deleted' 
                : 'deleted';
            
            $model->auditarAccion($accion, 'Registro eliminado');
        });

        // Al restaurar (si usa SoftDeletes)
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->auditarAccion('restored', 'Registro restaurado');
            });
        }
    }

    /**
     * Verifica si hay cambios auditables
     */
    protected function hasAuditableChanges(): bool
    {
        $dirty = $this->getDirty();
        $excluidos = $this->getAuditableExcluded();
        
        // Remover campos excluidos
        $cambios = array_diff_key($dirty, array_flip($excluidos));
        
        return !empty($cambios);
    }

    /**
     * Auditar una acción personalizada
     */
    public function auditarAccion(
        string $accion, 
        ?string $descripcion = null, 
        ?array $metadata = null
    ): void {
        // Obtener actor actual
        $actor = $this->obtenerActor();

        // Preparar datos según la acción
        $datosAnteriores = null;
        $datosNuevos = null;

        if ($accion === 'created') {
            $datosNuevos = $this->getAuditableAttributes();
        } elseif ($accion === 'updated') {
            // Solo incluir campos que realmente cambiaron
            $cambios = $this->getDirty();
            $excluidos = $this->getAuditableExcluded();
            $cambios = array_diff_key($cambios, array_flip($excluidos));
            
            if (!empty($cambios)) {
                $datosAnteriores = [];
                $datosNuevos = [];
                
                foreach (array_keys($cambios) as $campo) {
                    $datosAnteriores[$campo] = $this->getOriginal($campo);
                    $datosNuevos[$campo] = $this->getAttribute($campo);
                }
            }
        } elseif (in_array($accion, ['deleted', 'soft_deleted'])) {
            $datosAnteriores = $this->getAuditableAttributes();
        } elseif ($accion === 'restored') {
            $datosNuevos = $this->getAuditableAttributes();
        }

        // Crear registro de auditoría
        AuditLog::create([
            'actor_id'         => $actor['id'],
            'actor_type'       => $actor['type'],
            'actor_nombre'     => $actor['nombre'],
            'actor_rol'        => $actor['rol'],
            'accion'           => $accion,
            'descripcion'      => $descripcion ?? $this->generarDescripcion($accion),
            'modelo'           => get_class($this),
            'modelo_id'        => $this->id,
            'modelo_nombre'    => $this->getNombreAuditable(),
            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos'     => $datosNuevos,
            'metadata'         => $metadata,
            'ip_address'       => Request::ip(),
            'user_agent'       => Request::userAgent(),
            'url'              => Request::fullUrl(),
            'metodo_http'      => Request::method(),
        ]);
    }

    /**
     * Obtener actor actual (usuario autenticado)
     * Soporta múltiples guards (web y sanctum)
     */
    protected function obtenerActor(): array
    {
        // Intentar guard web primero (UsuarioWeb)
        $userWeb = Auth::guard('web')->user();
        
        if ($userWeb instanceof UsuarioWeb) {
            return [
                'id'     => $userWeb->id,
                'type'   => AuditLog::ACTOR_USUARIO_WEB,
                'nombre' => $userWeb->nombre,
                'rol'    => $userWeb->rol,
            ];
        }

        // Intentar guard sanctum (UsuarioApp o UsuarioWeb)
        $userSanctum = Auth::guard('sanctum')->user();
        
        if ($userSanctum instanceof UsuarioWeb) {
            return [
                'id'     => $userSanctum->id,
                'type'   => AuditLog::ACTOR_USUARIO_WEB,
                'nombre' => $userSanctum->nombre,
                'rol'    => $userSanctum->rol,
            ];
        }
        
        if ($userSanctum instanceof UsuarioApp) {
            return [
                'id'     => $userSanctum->id,
                'type'   => AuditLog::ACTOR_USUARIO_APP,
                'nombre' => $userSanctum->nombre_completo,
                'rol'    => 'usuario_app',
            ];
        }

        // Si no hay usuario autenticado, es el sistema
        return [
            'id'     => null,
            'type'   => AuditLog::ACTOR_SISTEMA,
            'nombre' => 'Sistema',
            'rol'    => null,
        ];
    }

    /**
     * Obtener atributos auditables (excluye campos sensibles)
     */
    protected function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();

        // Excluir campos sensibles
        $excluidos = $this->getAuditableExcluded();

        // Si el modelo define campos específicos a incluir
        if (property_exists($this, 'auditInclude')) {
            $attributes = array_intersect_key(
                $attributes, 
                array_flip($this->auditInclude)
            );
        }

        return array_diff_key($attributes, array_flip($excluidos));
    }

    /**
     * Campos a excluir de la auditoría
     * Puede ser sobrescrito en el modelo con la propiedad $auditExclude
     */
    protected function getAuditableExcluded(): array
    {
        $defaultExcluded = [
            'password',
            'remember_token',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        // Si el modelo define campos adicionales a excluir
        if (property_exists($this, 'auditExclude')) {
            return array_merge($defaultExcluded, $this->auditExclude);
        }

        return $defaultExcluded;
    }

    /**
     * Nombre legible del modelo para auditoría
     * Debe ser sobrescrito en cada modelo que use el trait
     */
    protected function getNombreAuditable(): string
    {
        // Intentar obtener un nombre representativo
        if (isset($this->nombre)) {
            return $this->nombre;
        }
        
        if (isset($this->email)) {
            return $this->email;
        }

        if (isset($this->codigo_modular_ie)) {
            return $this->codigo_modular_ie;
        }

        if (isset($this->codigo_modular)) {
            return $this->codigo_modular;
        }

        if (method_exists($this, 'getNombreCompleto')) {
            return $this->getNombreCompleto();
        }

        if (isset($this->descripcion)) {
            return $this->descripcion;
        }

        return class_basename(get_class($this)) . " #{$this->id}";
    }

    /**
     * Generar descripción automática según acción
     */
    protected function generarDescripcion(string $accion): string
    {
        $modelo = $this->getNombreModelo();
        $nombre = $this->getNombreAuditable();

        return match ($accion) {
            'created'       => "Creó {$modelo}: {$nombre}",
            'updated'       => "Actualizó {$modelo}: {$nombre}",
            'deleted'       => "Eliminó {$modelo}: {$nombre}",
            'soft_deleted'  => "Eliminó (soft) {$modelo}: {$nombre}",
            'restored'      => "Restauró {$modelo}: {$nombre}",
            default         => "{$accion} en {$modelo}: {$nombre}",
        };
    }

    /**
     * Obtiene el nombre del modelo en español
     */
    protected function getNombreModelo(): string
    {
        $modelName = class_basename(get_class($this));
        
        // Mapeo de nombres de modelos a español
        $nombres = [
            'Asistencia'             => 'asistencia',
            'Institucion'            => 'institución',
            'UsuarioApp'             => 'usuario app',
            'UsuarioWeb'             => 'usuario web',
            'HorarioInstitucion'     => 'horario',
            'UsuarioAppInstitucion'  => 'asignación',
            'SupervisorInstitucion'  => 'supervisión',
            'Justificacion'          => 'justificación',
            'Feriado'                => 'feriado',
            'ImportacionLog'         => 'importación',
            'AuditLog'               => 'log de auditoría',
        ];

        return $nombres[$modelName] ?? strtolower($modelName);
    }

    /**
     * Relación a logs de auditoría de este modelo específico
     */
    public function auditLogs()
    {
        return AuditLog::where('modelo', get_class($this))
                       ->where('modelo_id', $this->id)
                       ->orderBy('created_at', 'desc');
    }

    /**
     * Obtiene el último log de auditoría
     */
    public function ultimoAuditLog(): ?AuditLog
    {
        return $this->auditLogs()->first();
    }

    /**
     * Obtiene logs de auditoría con paginación
     */
    public function auditLogsPaginados(int $perPage = 15)
    {
        return $this->auditLogs()->paginate($perPage);
    }

    /**
     * Obtiene el historial de cambios de un campo específico
     */
    public function historialCampo(string $campo)
    {
        return $this->auditLogs()
                    ->where(function ($query) use ($campo) {
                        $query->whereJsonContains('datos_anteriores->' . $campo, null, 'or')
                              ->whereJsonContains('datos_nuevos->' . $campo, null);
                    })
                    ->get()
                    ->map(function ($log) use ($campo) {
                        return [
                            'fecha' => $log->created_at,
                            'actor' => $log->actor_nombre,
                            'anterior' => data_get($log->datos_anteriores, $campo),
                            'nuevo' => data_get($log->datos_nuevos, $campo),
                        ];
                    });
    }
}