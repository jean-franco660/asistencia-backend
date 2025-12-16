<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\Auditable;

class Institucion extends Model
{
    use Auditable;

    protected $table = 'instituciones';

    protected $fillable = [
        'codigo_modular_ie',
        'nombre',
        'distrito',
        'nivel_educativo',
        'centro_poblado',
        'direccion',
        'latitud',
        'longitud',
        'radio',
        'logo',
    ];

    protected $casts = [
        'latitud' => 'float',
        'longitud' => 'float',
        'radio' => 'float',
    ];

    /**
     * Relación N:M → una institución puede tener varios usuarios_app (docentes/directores).
     */
    public function docentes(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioApp::class,
            'docente_institucion',
            'institucion_id',   // FK en pivote hacia instituciones.id
            'usuario_app_id'    // FK en pivote hacia usuarios_app.id
        )
            ->withPivot(['estado', 'fecha_inicio', 'fecha_fin'])
            ->withTimestamps();
    }

    public function asistencias()
    {
        return $this->hasMany(\App\Models\Asistencia::class, 'institucion_id');
    }

    /**
     * Supervisores (usuarios_web) asignados a la institución
     */
    public function supervisores(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioWeb::class,
            'supervisor_institucion',
            'institucion_id',
            'usuario_web_id'
        )
            ->where('usuarios_web.rol', 'supervisor')
            ->withPivot(['fecha_inicio', 'fecha_fin'])
            ->withTimestamps();
    }

    public function horarios()
    {
        return $this->hasMany(HorarioInstitucion::class);
    }

    protected $appends = ['logo_url', 'nombre_display'];

    public function getLogoUrlAttribute()
    {
        if (!$this->logo)
            return null;
        return asset('storage/' . $this->logo);
    }

    /**
     * Retorna un nombre más descriptivo para mostrar en la interfaz.
     * Si el nombre es solo numérico (nomenclatura UGEL), retorna "IE {codigo_modular_ie}".
     * De lo contrario, retorna el nombre original.
     */
    public function getNombreDisplayAttribute(): string
    {
        // Si el nombre es solo numérico, mostrar con prefijo IE y código modular
        if (preg_match('/^\d+$/', $this->nombre)) {
            return "IE {$this->codigo_modular_ie}";
        }
        return $this->nombre;
    }

    protected function getNombreAuditable(): string
    {
        return "{$this->nombre} ({$this->codigo_modular_ie})";
    }

}
