<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use Carbon\Carbon;

/**
 * @property Carbon|null $fecha
 */
class Feriado extends Model
{

    use HasFactory, Auditable;

    protected $table = 'feriados';

    protected $fillable = [
        'tipo',
        'institucion_id',
        'fecha',
        'dia',
        'mes',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'fecha' => 'date',
        'dia' => 'integer',
        'mes' => 'integer',
        'tipo' => 'string',
        'institucion_id' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if ($model->fecha) {
                $model->dia = $model->fecha->day;
                $model->mes = $model->fecha->month;
            }
        });
    }

    public function institucion()
    {
        return $this->belongsTo(Institucion::class);
    }

    protected function getNombreAuditable(): string
    {
        return "{$this->descripcion} ({$this->fecha?->format('d/m/Y')})";
    }

}
