<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\SoftDeletes;

class UsuarioAppInstitucion extends Pivot
{
    use HasFactory, Auditable, SoftDeletes;

    protected $table = 'usuario_app_institucion';

    /* =========================
     * CONSTANTES - ESTADOS
     * ========================= */

    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_INACTIVO = 'INACTIVO';
    public const ESTADO_PENDIENTE = 'PENDIENTE';

    /* =========================
     * CONSTANTES - CARGOS (Ejemplos comunes, pero NO limitantes)
     * ========================= */

    // Nota: Estos son solo ejemplos. El sistema acepta cualquier cargo
    // que sea ingresado por el usuario o importado desde UGEL
    public const CARGO_DOCENTE = 'DOCENTE';
    public const CARGO_DIRECTOR = 'DIRECTOR';
    public const CARGO_SUBDIRECTOR = 'SUBDIRECTOR';
    public const CARGO_COORDINADOR = 'COORDINADOR';
    public const CARGO_AUXILIAR = 'AUXILIAR';
    public const CARGO_PSICOPEDAGOGO = 'PSICOPEDAGOGO';
    public const CARGO_TRABAJADOR_SOCIAL = 'TRABAJADOR_SOCIAL';
    public const CARGO_BIBLIOTECARIO = 'BIBLIOTECARIO';
    public const CARGO_ADMINISTRATIVO = 'ADMINISTRATIVO';
    public const CARGO_PERSONAL_SERVICIO = 'PERSONAL_SERVICIO';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

    protected $fillable = [
        'usuario_app_id',
        'institucion_id',
        'horario_institucion_id',
        'cargo',
        'estado',
    ];

    // ✅ Proteger fechas de asignación masiva - solo el Observer las modifica
    protected $guarded = ['id', 'fecha_inicio', 'fecha_fin'];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
    ];


    protected $attributes = [
        'estado' => self::ESTADO_PENDIENTE,
    ];

    /* =========================
     * RELACIONES
     * ========================= */

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    public function usuarioApp(): BelongsTo
    {
        return $this->usuario(); // Alias
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(HorarioInstitucion::class, 'horario_institucion_id');
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopeActivas($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    public function scopeInactivas($query)
    {
        return $query->where('estado', self::ESTADO_INACTIVO);
    }

    public function scopePorUsuario($query, int $usuarioAppId)
    {
        return $query->where('usuario_app_id', $usuarioAppId);
    }

    public function scopePorInstitucion($query, int $institucionId)
    {
        return $query->where('institucion_id', $institucionId);
    }

    public function scopePorHorario($query, int $horarioId)
    {
        return $query->where('horario_institucion_id', $horarioId);
    }

    public function scopePorCargo($query, string $cargo)
    {
        return $query->where('cargo', mb_strtoupper(trim($cargo)));
    }

    public function scopeDocentes($query)
    {
        return $query->where('cargo', self::CARGO_DOCENTE);
    }

    public function scopeDirectores($query)
    {
        return $query->where('cargo', self::CARGO_DIRECTOR);
    }

    /**
     * ✅ CORREGIDO: Usar constantes sin espacios
     */
    public function scopePersonalAdministrativo($query)
    {
        return $query->whereIn('cargo', [
            self::CARGO_ADMINISTRATIVO,
            self::CARGO_AUXILIAR,
            self::CARGO_PERSONAL_SERVICIO, // ✅ Sin espacio
        ]);
    }

    /**
     * ✅ CORREGIDO: Usar objetos Carbon en lugar de strings
     */
    /**
     * ✅ CORREGIDO: fecha_fin es EXCLUSIVA
     * Un vínculo es vigente si estado=ACTIVO y fecha_inicio <= D < fecha_fin
     */
    public function scopeVigentes($query, $fecha = null)
    {
        $fecha = $fecha ?? today();

        return $query->where('estado', self::ESTADO_ACTIVO)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_inicio')
                    ->orWhereDate('fecha_inicio', '<=', $fecha);
            })
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>', $fecha); // EXCLUSIVE
            });
    }

    public function scopeFinalizadas($query)
    {
        $hoy = today();

        return $query->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<', $hoy);
    }

    public function scopeFuturas($query)
    {
        $hoy = today();

        return $query->whereNotNull('fecha_inicio')
            ->whereDate('fecha_inicio', '>', $hoy);
    }

    public function scopeProximasAVencer($query, int $dias = 30)
    {
        $hoy = today();
        $limite = today()->addDays($dias);

        return $query->where('estado', self::ESTADO_ACTIVO)
            ->whereNotNull('fecha_fin')
            ->whereBetween('fecha_fin', [$hoy, $limite]);
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getDuracionDiasAttribute(): ?int
    {
        if (!$this->fecha_inicio || !$this->fecha_fin) {
            return null;
        }

        return $this->fecha_inicio->diffInDays($this->fecha_fin);
    }

    public function getDiasRestantesAttribute(): ?int
    {
        if (!$this->fecha_fin) {
            return null;
        }

        $hoy = today();

        if ($this->fecha_fin->isBefore($hoy)) {
            return 0;
        }

        return $hoy->diffInDays($this->fecha_fin);
    }

    public function getEstadoFormateadoAttribute(): string
    {
        return match ($this->estado) {
            self::ESTADO_ACTIVO => 'Activo',
            self::ESTADO_INACTIVO => 'Inactivo',
            default => 'Desconocido',
        };
    }

    /* =========================
     * HELPERS
     * ========================= */

    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    public function estaInactiva(): bool
    {
        return $this->estado === self::ESTADO_INACTIVO;
    }

    /**
     * ✅ CORREGIDO: Usar métodos Carbon para comparaciones
     */
    /**
     * ✅ CORREGIDO: fecha_fin es EXCLUSIVA
     */
    public function estaVigente(): bool
    {
        return $this->esVigenteEn(today());
    }

    /**
     * Verifica si el vínculo es vigente para una fecha específica
     * @param string|\Carbon\Carbon $fecha
     * @return bool
     */
    public function esVigenteEn($fecha): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVO) {
            return false;
        }

        $fecha = is_string($fecha) ? \Carbon\Carbon::parse($fecha) : $fecha;

        // Verificar fecha inicio (inclusive)
        if ($this->fecha_inicio && $this->fecha_inicio->isAfter($fecha)) {
            return false;
        }

        // Verificar fecha fin (EXCLUSIVE: válido hasta D < fecha_fin)
        if ($this->fecha_fin && $fecha->isSameOrAfter($this->fecha_fin)) {
            return false;
        }

        return true;
    }

    public function haFinalizado(): bool
    {
        if (!$this->fecha_fin) {
            return false;
        }

        return $this->fecha_fin->isBefore(today());
    }

    public function esFutura(): bool
    {
        if (!$this->fecha_inicio) {
            return false;
        }

        return $this->fecha_inicio->isAfter(today());
    }

    public function estaProximaAVencer(int $dias = 30): bool
    {
        if (!$this->fecha_fin || !$this->estaVigente()) {
            return false;
        }

        $limite = today()->addDays($dias);

        return $this->fecha_fin->between(today(), $limite);
    }

    public function esDocente(): bool
    {
        return $this->cargo === self::CARGO_DOCENTE;
    }

    public function esDirector(): bool
    {
        return $this->cargo === self::CARGO_DIRECTOR;
    }

    /* =========================
     * MÉTODOS DE NEGOCIO
     * ========================= */

    /**
     * Activa la asignación (Observer maneja fecha_inicio y fecha_fin automáticamente)
     */
    public function activar(): bool
    {
        return $this->update(['estado' => self::ESTADO_ACTIVO]);
    }

    /**
     * Desactiva la asignación (Observer establece fecha_fin automáticamente)
     */
    public function desactivar(): bool
    {
        return $this->update(['estado' => self::ESTADO_INACTIVO]);
    }

    /**
     * Finaliza la asignación en la fecha actual
     */
    public function finalizar(): bool
    {
        $this->fecha_fin = today();
        return $this->save();
    }

    /**
     * Extiende la asignación hasta una nueva fecha
     */
    public function extenderHasta(\Carbon\Carbon $nuevaFechaFin): bool
    {
        $this->fecha_fin = $nuevaFechaFin;
        return $this->save();
    }

    /**
     * ✅ CORREGIDO: Usar copy() para no modificar el Carbon original
     * Renueva la asignación por un periodo adicional
     */
    public function renovarPor(int $meses): bool
    {
        $nuevaFechaFin = $this->fecha_fin
            ? $this->fecha_fin->copy()->addMonths($meses) // ✅ Usar copy()
            : today()->addMonths($meses);

        return $this->extenderHasta($nuevaFechaFin);
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    /**
     * Obtiene todos los cargos únicos registrados en el sistema
     * Útil para autocompletado en formularios
     */
    public static function getCargosRegistrados(): array
    {
        return static::whereNotNull('cargo')
            ->distinct()
            ->pluck('cargo')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Obtiene cargos con su frecuencia de uso
     * Útil para sugerencias ordenadas por popularidad
     */
    public static function getCargosConFrecuencia(): array
    {
        return static::whereNotNull('cargo')
            ->selectRaw('cargo, COUNT(*) as total')
            ->groupBy('cargo')
            ->orderByDesc('total')
            ->pluck('total', 'cargo')
            ->toArray();
    }

    /**
     * Obtiene cargos únicos por institución
     */
    public static function getCargosPorInstitucion(int $institucionId): array
    {
        return static::where('institucion_id', $institucionId)
            ->whereNotNull('cargo')
            ->distinct()
            ->pluck('cargo')
            ->sort()
            ->values()
            ->toArray();
    }

    public static function getEstadosDisponibles(): array
    {
        return [
            self::ESTADO_ACTIVO,
            self::ESTADO_INACTIVO,
        ];
    }

    public static function getEstadosConEtiquetas(): array
    {
        return [
            self::ESTADO_ACTIVO => 'Activo',
            self::ESTADO_INACTIVO => 'Inactivo',
        ];
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        $usuario = $this->usuario?->nombre_completo ?? 'Usuario';
        $institucion = $this->institucion?->nombre ?? 'Institución';

        return "{$usuario} → {$institucion} ({$this->cargo})";
    }
}