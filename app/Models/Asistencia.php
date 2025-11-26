<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $fillable = [
        'usuario_id',
        'institucion_id',
        'fecha_hora',
        'dentro_rango',
        'latitud',
        'longitud',
        'foto',
        'tipo',
        'turno',
        'estado',     
        'falta',
        'sincronizado',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
        'dentro_rango' => 'boolean',
        'falta' => 'boolean',
        'sincronizado' => 'boolean',
        'latitud' => 'decimal:8',      // ✅ Cambiado de 7 a 8 para mejor precisión GPS
        'longitud' => 'decimal:8',     // ✅ Cambiado de 7 a 8 para mejor precisión GPS
        'estado' => 'string',   
    ];

    // ✅ Agregar valores por defecto
    protected $attributes = [
        'sincronizado' => false,
        'falta' => false,
        'dentro_rango' => false,
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_id');
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    public function getFechaHoraFormateadaAttribute(): string
    {
        return $this->fecha_hora ? $this->fecha_hora->format('d/m/Y H:i:s') : '';
    }

    public function esEntrada(): bool
    {
        return $this->tipo === 'entrada';
    }

    public function esSalida(): bool
    {
        return $this->tipo === 'salida';
    }

    

    // ✅ Agregar scope para asistencias no sincronizadas
    public function scopeNoSincronizadas($query)
    {
        return $query->where('sincronizado', false);
    }

    public function scopeSincronizadas($query)
    {
        return $query->where('sincronizado', true);
    }

    public function scopeDentroRango($query)
    {
        return $query->where('dentro_rango', true);
    }

    public function scopePorUsuario($query, $usuarioId)
    {
        return $query->where('usuario_id', $usuarioId);
    }

    public function scopePorFecha($query, $fecha)
    {
        return $query->whereDate('fecha_hora', $fecha);
    }

    // ✅ Agregar scope para entradas/salidas
    public function scopeEntradas($query)
    {
        return $query->where('tipo', 'entrada');
    }

    public function scopeSalidas($query)
    {
        return $query->where('tipo', 'salida');
    }

    // ✅ Agregar scope para rango de fechas
    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha_hora', [$desde, $hasta]);
    }
}