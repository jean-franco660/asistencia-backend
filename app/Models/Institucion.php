<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Institucion extends Model
{
    protected $table = 'instituciones';

    protected $fillable = [
        'nombre',
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
     * Relación N:M → una institución puede tener varios docentes.
     * Usa la tabla pivote "docente_institucion".
     */
    public function docentes(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioApp::class,
            'docente_institucion',
            'institucion_id',
            'usuario_app_id'
        )->withTimestamps();
    }

    public function asistencias()
    {
        return $this->hasMany(\App\Models\Asistencia::class, 'institucion_id');
    }


    /**
     * Relación N:M → una institución puede tener varios directores.
     * Usa la tabla pivote "director_institucion".
     */
    public function directores(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioWeb::class,
            'director_institucion',
            'institucion_id',
            'usuario_web_id'
        )
        ->withPivot(['fecha_inicio', 'fecha_fin'])
        ->withTimestamps();
    }

    /**
     * Devuelve el ID del director actual (sin fecha_fin).
     */
    public function getDirectorActualIdAttribute()
    {
        return $this->directores()
            ->wherePivotNull('fecha_fin')
            ->value('usuario_web_id');
    }

    public function horarios()
    {
    return $this->hasMany(HorarioInstitucion::class);
    }


}
