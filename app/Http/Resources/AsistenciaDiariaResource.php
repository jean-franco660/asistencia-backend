<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa un registro de marcación individual (entrada o salida) de la asistencia diaria.
 * Forma parte de la colección 'marcaciones' incluida en AsistenciaResource.
 * Incluye datos de geolocalización, estado de marcación, información de revisión
 * y soporte para sincronización offline mediante UUID.
 */
class AsistenciaDiariaResource extends JsonResource
{
    /**
     * Transforma el modelo en un array para la respuesta JSON.
     * El campo 'marcada_en' y 'synced_at' se formatean explícitamente a 'Y-m-d H:i:s'
     * para garantizar consistencia independientemente de la configuración de zona horaria.
     * El campo 'dentro_rango' se castea a booleano para asegurar el tipo en el JSON.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asistencia_id' => $this->asistencia_id,
            'tipo' => $this->tipo,
            'marcada_en' => $this->marcada_en?->format('Y-m-d H:i:s'),
            'latitud' => $this->latitud,
            'longitud' => $this->longitud,
            'distancia_m' => $this->distancia_m,
            'dentro_rango' => (bool)$this->dentro_rango,
            'estado_marcacion' => $this->estado_marcacion,
            'motivo' => $this->motivo,
            'observacion' => $this->observacion,
            'foto_url' => $this->foto_url,
            'offline_uuid' => $this->offline_uuid,
            'registrado_en' => $this->registrado_en,
            'synced_at' => $this->synced_at?->format('Y-m-d H:i:s'),
            'meta' => $this->meta,
            'estado_revision' => $this->estado_revision,
            'revisado_por_usuario_web_id' => $this->revisado_por_usuario_web_id,
            'revisado_en' => $this->revisado_en?->format('Y-m-d H:i:s'),
            'revision_observacion' => $this->revision_observacion,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
