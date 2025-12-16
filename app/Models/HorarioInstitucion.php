<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class HorarioInstitucion extends Model
{
    
    use HasFactory, Auditable;

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

    protected function getNombreAuditable(): string
    {
    return "{$this->nombre_turno} - {$this->hora_entrada} a {$this->hora_salida}";
    }
}