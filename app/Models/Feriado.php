<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;
use Carbon\Carbon;

/**
 * Representa un día feriado, ya sea de alcance nacional o específico de una institución.
 *
 * Almacena la fecha completa y los campos `dia` y `mes` (generados automáticamente
 * desde `fecha` en el evento `saving`) para facilitar consultas sin referencia al año,
 * permitiendo reutilizar los feriados recurrentes (p.ej. fiestas patrias).
 *
 * Tabla: feriados
 * Relaciones principales: institucion (Institucion)
 *
 * @property Carbon|null $fecha
 */
class Feriado extends Model
{
    use HasFactory, Auditable;

    protected $table = 'feriados';

    public const TIPO_NACIONAL      = 'nacional';
    public const TIPO_INSTITUCIONAL = 'institucional';

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

    protected $attributes = [
        'activo' => true,
    ];

    /**
     * Completa automáticamente los campos `dia` y `mes` a partir de `fecha` antes de persistir.
     * Esto permite filtrar feriados por día y mes sin depender del año.
     */
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

    /**
     * Institución propietaria del feriado (solo aplica para feriados de tipo institucional).
     */
    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class);
    }

    /**
     * Retorna la fecha formateada como 'd/m/Y'.
     */
    public function getFechaFormateadaAttribute(): string
    {
        return $this->fecha?->format('d/m/Y') ?? '';
    }

    /**
     * Retorna el nombre del día de la semana correspondiente a la fecha, en español.
     */
    public function getDiaNombreAttribute(): string
    {
        if (!$this->fecha) {
            return '';
        }

        return $this->fecha->locale('es')->isoFormat('dddd');
    }

    /**
     * Filtra feriados activos.
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Filtra feriados inactivos.
     */
    public function scopeInactivos($query)
    {
        return $query->where('activo', false);
    }

    /**
     * Filtra feriados de tipo nacional.
     */
    public function scopeNacionales($query)
    {
        return $query->where('tipo', self::TIPO_NACIONAL);
    }

    /**
     * Filtra feriados de tipo institucional.
     */
    public function scopeInstitucionales($query)
    {
        return $query->where('tipo', self::TIPO_INSTITUCIONAL);
    }

    /**
     * Filtra feriados institucionales de una institución específica.
     */
    public function scopePorInstitucion($query, int $institucionId)
    {
        return $query->where('tipo', self::TIPO_INSTITUCIONAL)
                     ->where('institucion_id', $institucionId);
    }

    /**
     * Filtra feriados que coinciden con el día y mes de la fecha proporcionada,
     * independientemente del año (aplica para feriados recurrentes anuales).
     */
    public function scopePorFecha($query, Carbon $fecha)
    {
        return $query->where('dia', $fecha->day)
                     ->where('mes', $fecha->month);
    }

    /**
     * Filtra feriados por año exacto.
     */
    public function scopePorAnio($query, int $anio)
    {
        return $query->whereYear('fecha', $anio);
    }

    /**
     * Retorna los próximos feriados activos ordenados por fecha ascendente.
     */
    public function scopeProximosFeriados($query, int $limite = 10)
    {
        $hoy = now();
        
        return $query->where('activo', true)
                     ->where('fecha', '>=', $hoy)
                     ->orderBy('fecha', 'asc')
                     ->limit($limite);
    }

    /**
     * Filtra feriados del mes indicado. Si se proporciona el año, restringe también por él.
     */
    public function scopeDelMes($query, int $mes, ?int $anio = null)
    {
        $query->where('mes', $mes);
        
        if ($anio) {
            $query->whereYear('fecha', $anio);
        }
        
        return $query;
    }

    /**
     * Filtra feriados del año indicado. Si no se proporciona, usa el año actual.
     */
    public function scopeDelAnio($query, ?int $anio = null)
    {
        $anio = $anio ?? now()->year;
        return $query->whereYear('fecha', $anio);
    }

    /**
     * Indica si el feriado es de tipo nacional.
     */
    public function esNacional(): bool
    {
        return $this->tipo === self::TIPO_NACIONAL;
    }

    /**
     * Indica si el feriado es de tipo institucional.
     */
    public function esInstitucional(): bool
    {
        return $this->tipo === self::TIPO_INSTITUCIONAL;
    }

    /**
     * Indica si el feriado está activo.
     */
    public function estaActivo(): bool
    {
        return $this->activo === true;
    }

    /**
     * Indica si la fecha del feriado ya ha pasado respecto al momento actual.
     */
    public function yaPaso(): bool
    {
        return $this->fecha < now()->toDateString();
    }

    /**
     * Indica si la fecha del feriado es hoy.
     */
    public function esHoy(): bool
    {
        return $this->fecha->isToday();
    }

    /**
     * Indica si la fecha del feriado aún no ha llegado.
     */
    public function esProximo(): bool
    {
        return $this->fecha > now()->toDateString();
    }

    /**
     * Verifica si una fecha corresponde a un día feriado.
     * Primero comprueba feriados nacionales; si se indica una institución,
     * también evalúa sus feriados institucionales.
     */
    public static function esFeriado(Carbon $fecha, ?int $institucionId = null): bool
    {
        $query = static::activos()
                       ->where('dia', $fecha->day)
                       ->where('mes', $fecha->month);

        $nacional = (clone $query)->where('tipo', self::TIPO_NACIONAL)->exists();

        if ($nacional) {
            return true;
        }

        if ($institucionId) {
            return (clone $query)
                ->where('tipo', self::TIPO_INSTITUCIONAL)
                ->where('institucion_id', $institucionId)
                ->exists();
        }

        return false;
    }

    /**
     * Obtiene todos los feriados aplicables a una fecha e institución.
     * Devuelve feriados nacionales más los institucionales de la institución indicada.
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
     * Retorna los feriados del año indicado (o del año actual), ordenados por fecha.
     * Si se proporciona una institución, incluye también sus feriados institucionales.
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
     * Cuenta los días laborales entre dos fechas, excluyendo sábados, domingos y feriados.
     *
     * Precarga todos los feriados aplicables en un set indexado por 'mes-dia' para
     * evitar consultas N+1 dentro del bucle de iteración de fechas.
     */
    public static function contarDiasLaborales(Carbon $desde, Carbon $hasta, ?int $institucionId = null): int
    {
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
            if (!$fecha->isWeekend()) {
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
     * Retorna la lista de tipos de feriado disponibles.
     */
    public static function getTiposDisponibles(): array
    {
        return [
            self::TIPO_NACIONAL,
            self::TIPO_INSTITUCIONAL,
        ];
    }

    /**
     * Retorna los tipos de feriado con su etiqueta legible.
     */
    public static function getTiposConEtiquetas(): array
    {
        return [
            self::TIPO_NACIONAL      => 'Nacional',
            self::TIPO_INSTITUCIONAL => 'Institucional',
        ];
    }

    /**
     * Retorna el nombre legible utilizado en el registro de auditoría.
     */
    protected function getNombreAuditable(): string
    {
        return "{$this->descripcion} ({$this->fecha?->format('d/m/Y')})";
    }
}