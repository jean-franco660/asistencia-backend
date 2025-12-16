<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
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
     * Relación polimórfica con el actor (quien realizó la acción)
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

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
                    'nuevo' => $nuevo,
                ];
            }
        }

        return $cambios;
    }

    /**
     * Scopes útiles
     */
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
            'autorizado',
            'rechazado',
            'deleted',
            'importado',
            'masivo',
        ]);
    }
}