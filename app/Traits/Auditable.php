<?php

namespace App\Traits;

use App\Models\AuditLog;
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
            $model->auditarAccion('updated', 'Registro actualizado');
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
            $datosAnteriores = $this->getOriginal();
            $datosNuevos = $this->getAuditableAttributes();
        } elseif (in_array($accion, ['deleted', 'soft_deleted'])) {
            $datosAnteriores = $this->getAuditableAttributes();
        }

        // Crear registro de auditoría
        AuditLog::create([
            'actor_id' => $actor['id'],
            'actor_type' => $actor['type'],
            'actor_nombre' => $actor['nombre'],
            'actor_rol' => $actor['rol'],
            'accion' => $accion,
            'descripcion' => $descripcion ?? $this->generarDescripcion($accion),
            'modelo' => get_class($this),
            'modelo_id' => $this->id,
            'modelo_nombre' => $this->getNombreAuditable(),
            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos' => $datosNuevos,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'url' => Request::fullUrl(),
            'metodo_http' => Request::method(),
        ]);
    }

    /**
     * Obtener actor actual (usuario autenticado)
     */
    protected function obtenerActor(): array
    {
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return [
                'id' => null,
                'type' => null,
                'nombre' => 'Sistema',
                'rol' => 'system',
            ];
        }

        return [
            'id' => $user->id,
            'type' => get_class($user),
            'nombre' => $user->nombre ?? $user->nombre_completo ?? 'Usuario',
            'rol' => $user->rol ?? 'desconocido',
        ];
    }

    /**
     * Obtener atributos auditables (excluye campos sensibles)
     */
    protected function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();

        // Excluir campos sensibles por defecto
        $excluidos = $this->getAuditableExcluded();

        return array_diff_key($attributes, array_flip($excluidos));
    }

    /**
     * Campos a excluir de la auditoría (override en el modelo si necesitas)
     */
    protected function getAuditableExcluded(): array
    {
        return [
            'password',
            'remember_token',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * Nombre legible del modelo para auditoría (override en cada modelo)
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

        if (isset($this->codigo_modular_docente)) {
            return $this->codigo_modular_docente;
        }

        return "#{$this->id}";
    }

    /**
     * Generar descripción automática según acción
     */
    protected function generarDescripcion(string $accion): string
    {
        $modelo = class_basename(get_class($this));
        $nombre = $this->getNombreAuditable();

        return match ($accion) {
            'created' => "{$modelo} '{$nombre}' creado",
            'updated' => "{$modelo} '{$nombre}' actualizado",
            'deleted', 'soft_deleted' => "{$modelo} '{$nombre}' eliminado",
            'restored' => "{$modelo} '{$nombre}' restaurado",
            default => "{$modelo} '{$nombre}' - acción: {$accion}",
        };
    }

    /**
     * Relación a logs de auditoría
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'modelo', 'modelo', 'modelo_id');
    }
}