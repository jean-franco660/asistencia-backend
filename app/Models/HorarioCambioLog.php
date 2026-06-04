<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registra cambios realizados al horario asignado a un usuario en una institución.
 *
 * Cada entrada guarda una instantánea del horario anterior y el nuevo (como JSON),
 * el origen del cambio (importación, asignación manual, etc.) y el administrador
 * responsable cuando aplica.
 *
 * Tabla: horarios_cambios_log
 * Relaciones principales: usuario (UsuarioApp), institucion (Institucion), admin (UsuarioWeb)
 */
class HorarioCambioLog extends Model
{
    protected $table = 'horarios_cambios_log';

    protected $fillable = [
        'usuario_app_id',
        'institucion_id',
        'horario_anterior',
        'horario_nuevo',
        'origen',
        'usuario_admin_id',
        'motivo',
    ];

    protected $casts = [
        'horario_anterior' => 'array',
        'horario_nuevo' => 'array',
    ];

    /**
     * Usuario de la app cuyo horario fue modificado.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    /**
     * Institución en la que se realizó el cambio de horario.
     */
    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    /**
     * Administrador (usuario web) que autorizó o ejecutó el cambio.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'usuario_admin_id');
    }
}
