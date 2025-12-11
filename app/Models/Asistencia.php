<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage; // ✅ IMPORTANTE

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
        'falta_entrada',
        'falta_salida',
        'sincronizado',
    ];

    protected $casts = [
        'fecha_hora'     => 'datetime',
        'dentro_rango'   => 'boolean',
        'falta'          => 'boolean',
        'falta_entrada'  => 'boolean',
        'falta_salida'   => 'boolean',
        'sincronizado'   => 'boolean',
        'latitud'        => 'decimal:8',
        'longitud'       => 'decimal:8',
        'estado'         => 'string',
    ];

    protected $attributes = [
        'sincronizado'   => false,
        'falta'          => false,
        'falta_entrada'  => false,
        'falta_salida'   => false,
        'dentro_rango'   => false,
    ];

    // ✅ Para que en el JSON salga "selfie_url"
    protected $appends = ['selfie_url'];

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

    public function esFaltaEntrada(): bool
    {
        return $this->falta_entrada === true;
    }

    public function esFaltaSalida(): bool
    {
        return $this->falta_salida === true;
    }

    public function esFaltaCompleta(): bool
    {
        return $this->falta_entrada && $this->falta_salida;
    }

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

    public function scopeEntradas($query)
    {
        return $query->where('tipo', 'entrada');
    }

    public function scopeSalidas($query)
    {
        return $query->where('tipo', 'salida');
    }

    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha_hora', [$desde, $hasta]);
    }

    /*
    |--------------------------------------------------------------------------
    | URL DE SELFIE (S3)
    |--------------------------------------------------------------------------
    | Esto es lo que leerá Flutter como `selfie_url`
    */
    public function getSelfieUrlAttribute()
    {
        if (!$this->foto) {
            return null;
        }

        // 1) Intentar usar la URL base configurada en config/app.php
        $base = config('app.images_base_url');

        if (!empty($base)) {
            return rtrim($base, '/') . '/' . ltrim($this->foto, '/');
        }

        // 2) Fallback: si no hay ASISTENCIAS_BASE_URL, usar S3 directamente
        try {
            return Storage::disk('s3')->url($this->foto);
        } catch (\Throwable $e) {
            \Log::error("Error generando URL de S3 para asistencia {$this->id}: " . $e->getMessage());
            return null;
        }
    }

}
