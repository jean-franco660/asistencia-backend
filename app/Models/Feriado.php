<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;
use Carbon\Carbon;

/**
 * @property Carbon|null $fecha
 */
class Feriado extends Model
{
    use HasFactory, Auditable;

    protected $table = 'feriados';

    /* =========================
     * CONSTANTES - TIPOS
     * ========================= */

    public const TIPO_NACIONAL      = 'nacional';
    public const TIPO_INSTITUCIONAL = 'institucional';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

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
        'activo'         => 'boolean',
        'fecha'          => 'date',
        'dia'            => 'integer',
        'mes'            => 'integer',
        'tipo'           => 'string',
        'institucion_id' => 'integer',
    ];

    protected $attributes = [
        'activo' => true,
    ];

    /* =========================
     * EVENTOS DEL MODELO
     * ========================= */

    protected static function boot()
    {
        parent::boot();

        // Auto-completar día y mes desde la fecha
        static::saving(function ($model) {
            if ($model->fecha) {
                $model->dia = $model->fecha->day;
                $model->mes = $model->fecha->month;
            }
        });
    }

    /* =========================
     * RELACIONES
     * ========================= */

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class);
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getFechaFormateadaAttribute(): string
    {
        return $this->fecha?->format('d/m/Y') ?? '';
    }

    public function getDiaNombreAttribute(): string
    {
        if (!$this->fecha) {
            return '';
        }

        return $this->fecha->locale('es')->isoFormat('dddd');
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeInactivos($query)
    {
        return $query->where('activo', false);
    }

    public function scopeNacionales($query)
    {
        return $query->where('tipo', self::TIPO_NACIONAL);
    }

    public function scopeInstitucionales($query)
    {
        return $query->where('tipo', self::TIPO_INSTITUCIONAL);
    }

    public function scopePorInstitucion($query, int $institucionId)
    {
        return $query->where('tipo', self::TIPO_INSTITUCIONAL)
                     ->where('institucion_id', $institucionId);
    }

    public function scopePorFecha($query, Carbon $fecha)
    {
        return $query->where('dia', $fecha->day)
                     ->where('mes', $fecha->month);
    }

    public function scopePorAnio($query, int $anio)
    {
        return $query->whereYear('fecha', $anio);
    }

    public function scopeProximosFeriados($query, int $limite = 10)
    {
        $hoy = now();
        
        return $query->where('activo', true)
                     ->where('fecha', '>=', $hoy)
                     ->orderBy('fecha', 'asc')
                     ->limit($limite);
    }

    public function scopeDelMes($query, int $mes, ?int $anio = null)
    {
        $query->where('mes', $mes);
        
        if ($anio) {
            $query->whereYear('fecha', $anio);
        }
        
        return $query;
    }

    public function scopeDelAnio($query, ?int $anio = null)
    {
        $anio = $anio ?? now()->year;
        return $query->whereYear('fecha', $anio);
    }

    /* =========================
     * HELPERS
     * ========================= */

    public function esNacional(): bool
    {
        return $this->tipo === self::TIPO_NACIONAL;
    }

    public function esInstitucional(): bool
    {
        return $this->tipo === self::TIPO_INSTITUCIONAL;
    }

    public function estaActivo(): bool
    {
        return $this->activo === true;
    }

    public function yaPaso(): bool
    {
        return $this->fecha < now()->toDateString();
    }

    public function esHoy(): bool
    {
        return $this->fecha->isToday();
    }

    public function esProximo(): bool
    {
        return $this->fecha > now()->toDateString();
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    /**
     * Verifica si una fecha es feriado
     */
    public static function esFeriado(Carbon $fecha, ?int $institucionId = null): bool
    {
        $query = static::activos()
                       ->where('dia', $fecha->day)
                       ->where('mes', $fecha->month);

        // Buscar feriados nacionales
        $nacional = (clone $query)->where('tipo', self::TIPO_NACIONAL)->exists();

        if ($nacional) {
            return true;
        }

        // Si se proporciona institución, buscar feriados institucionales
        if ($institucionId) {
            return (clone $query)
                ->where('tipo', self::TIPO_INSTITUCIONAL)
                ->where('institucion_id', $institucionId)
                ->exists();
        }

        return false;
    }

    /**
     * Obtiene todos los feriados aplicables a una fecha e institución
     */
    public static function getFeriadosAplicables(Carbon $fecha, ?int $institucionId = null)
    {
        $query = static::activos()
                       ->where('dia', $fecha->day)
                       ->where('mes', $fecha->month);

        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->where('tipo', self::TIPO_NACIONAL)
                  ->orWhere(function ($sq) use ($institucionId) {
                      $sq->where('tipo', self::TIPO_INSTITUCIONAL)
                         ->where('institucion_id', $institucionId);
                  });
            });
        } else {
            $query->where('tipo', self::TIPO_NACIONAL);
        }

        return $query->get();
    }

    /**
     * Obtiene feriados del año actual
     */
    public static function getFeriadosDelAnio(?int $anio = null, ?int $institucionId = null)
    {
        $anio = $anio ?? now()->year;
        
        $query = static::activos()
                       ->whereYear('fecha', $anio)
                       ->orderBy('fecha', 'asc');

        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->where('tipo', self::TIPO_NACIONAL)
                  ->orWhere(function ($sq) use ($institucionId) {
                      $sq->where('tipo', self::TIPO_INSTITUCIONAL)
                         ->where('institucion_id', $institucionId);
                  });
            });
        } else {
            $query->where('tipo', self::TIPO_NACIONAL);
        }

        return $query->get();
    }

    /**
     * Cuenta días laborales entre dos fechas (excluyendo feriados y fines de semana)
     */
    public static function contarDiasLaborales(Carbon $desde, Carbon $hasta, ?int $institucionId = null): int
    {
        // Precargar feriados aplicables para evitar N+1 queries en el bucle
        $feriadosQuery = static::activos();
        
        if ($institucionId) {
            $feriadosQuery->where(function ($q) use ($institucionId) {
                $q->where('tipo', self::TIPO_NACIONAL)
                  ->orWhere(function ($sq) use ($institucionId) {
                      $sq->where('tipo', self::TIPO_INSTITUCIONAL)
                         ->where('institucion_id', $institucionId);
                  });
            });
        } else {
            $feriadosQuery->where('tipo', self::TIPO_NACIONAL);
        }

        $feriados = $feriadosQuery->get(['dia', 'mes']);
        
        $feriadosSet = [];
        foreach ($feriados as $feriado) {
            $feriadosSet[$feriado->mes . '-' . $feriado->dia] = true;
        }

        $dias = 0;
        $fecha = $desde->copy();

        while ($fecha->lte($hasta)) {
            // Excluir sábados y domingos
            if (!$fecha->isWeekend()) {
                // Excluir feriados
                $clave = $fecha->month . '-' . $fecha->day;
                if (!isset($feriadosSet[$clave])) {
                    $dias++;
                }
            }
            $fecha->addDay();
        }

        return $dias;
    }

    /**
     * Obtiene tipos disponibles
     */
    public static function getTiposDisponibles(): array
    {
        return [
            self::TIPO_NACIONAL,
            self::TIPO_INSTITUCIONAL,
        ];
    }

    /**
     * Obtiene tipos con etiquetas
     */
    public static function getTiposConEtiquetas(): array
    {
        return [
            self::TIPO_NACIONAL      => 'Nacional',
            self::TIPO_INSTITUCIONAL => 'Institucional',
        ];
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        return "{$this->descripcion} ({$this->fecha?->format('d/m/Y')})";
    }
}