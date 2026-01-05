<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(UsuarioWeb::class, 'usuario_admin_id');
    }
}
