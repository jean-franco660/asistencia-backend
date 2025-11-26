<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HorarioInstitucion extends Model
{
    use HasFactory;

    protected $table = 'horarios_institucion';

    protected $fillable = [
        'institucion_id',
        'nombre_turno',
        'hora_entrada',
        'hora_salida',
        'tolerancia_minutos',
        'dias_semana', 
        'activo',
    ];


    protected $casts = [
        'dias_semana' => 'array',
        'activo' => 'boolean',
    ];

    // Relación: cada horario pertenece a una institución
    public function institucion()
    {
        return $this->belongsTo(Institucion::class);
    }
}