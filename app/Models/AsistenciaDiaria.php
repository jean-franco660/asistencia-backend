<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AsistenciaDiaria extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'asistencias_diarias';

    protected $fillable = [
        'asistencia_id',
        'tipo', // ENTRADA, SALIDA
        'marcada_en',
        'latitud',
        'longitud',
        'distancia_m',
        'dentro_rango', // boolean
        'estado_marcacion', // VALIDA, OBSERVADA, ANULADA
        'motivo',
        'observacion',
        'foto_url',
        'offline_uuid',
        'registrado_en',
        'synced_at',
        'meta',
        // Review fields
        'estado_revision',
        'revisado_por_usuario_web_id',
        'revisado_en',
        'revision_observacion',
    ];

    protected $casts = [
        'marcada_en' => 'datetime',
        'synced_at' => 'datetime',
        'revisado_en' => 'datetime',
        'dentro_rango' => 'boolean',
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
        'meta' => 'array',
    ];

    // Tipos de marcación
    public const TIPO_ENTRADA = 'ENTRADA';
    public const TIPO_SALIDA = 'SALIDA';

    // Estados de marcación
    public const ESTADO_VALIDA = 'VALIDA';
    public const ESTADO_OBSERVADA = 'OBSERVADA';
    public const ESTADO_ANULADA = 'ANULADA';

    // Motivos comunes
    public const MOTIVO_OK = 'OK';
    public const MOTIVO_FUERA_DE_HORARIO = 'FUERA_DE_HORARIO';
    public const MOTIVO_RELOJ_NO_CONFIABLE = 'RELOJ_NO_CONFIABLE';
    public const MOTIVO_FUERA_DE_RANGO = 'FUERA_DE_RANGO';
    public const MOTIVO_SIN_GPS = 'SIN_GPS';

    // Estados de revisión humana
    public const REVISION_PENDIENTE = 'PENDIENTE';
    public const REVISION_APROBADA = 'APROBADA';
    public const REVISION_MANTENER_OBSERVADA = 'MANTENER_OBSERVADA';

    /**
     * Relación con la cabecera (Asistencia).
     */
    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }

    /**
     * Usuario web que revisó/aprobó.
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'revisado_por_usuario_web_id');
    }
}
