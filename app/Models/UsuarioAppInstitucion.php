<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Representa la asignación de un docente (UsuarioApp) a una institución con un horario y cargo específicos.
 *
 * Extiende Pivot para poder ser usada como modelo de tabla intermedia en la relación many-to-many
 * entre UsuarioApp e Institucion, pero también funciona como modelo independiente con scopes,
 * helpers y métodos de negocio propios. Admite soft deletes y auditoría.
 * Las fechas fecha_inicio y fecha_fin son gestionadas exclusivamente por el Observer del modelo;
 * fecha_fin es EXCLUSIVA: el vínculo es válido mientras fecha < fecha_fin.
 */
class UsuarioAppInstitucion extends Pivot
{
    use HasFactory, Auditable, SoftDeletes;

    protected $table = 'usuario_app_institucion';



    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_INACTIVO = 'INACTIVO';
    public const ESTADO_PENDIENTE = 'PENDIENTE';

    // Los cargos predefinidos son solo valores de referencia; el sistema acepta cualquier cargo
    // ingresado por el usuario o importado desde la UGEL.
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



    protected $fillable = [
        'usuario_app_id',
        'institucion_id',
        'horario_institucion_id',
        'cargo',
        'estado',
    ];

    // Las fechas de vigencia son gestionadas exclusivamente por el Observer; no deben asignarse masivamente
    protected $guarded = ['id', 'fecha_inicio', 'fecha_fin'];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
    ];


    protected $attributes = [
        'estado' => self::ESTADO_PENDIENTE,
    ];

    /**
     * Retorna el docente asociado a esta asignación.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(UsuarioApp::class, 'usuario_app_id');
    }

    /**
     * Alias de usuario(). Retorna el docente asociado a esta asignación.
     */
    public function usuarioApp(): BelongsTo
    {
        return $this->usuario();
    }

    /**
     * Retorna la institución a la que corresponde esta asignación.
     */
    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class);
    }

    /**
     * Retorna el horario institucional asignado al docente en esta asignación.
     */
    public function horario(): BelongsTo
    {
        return $this->belongsTo(HorarioInstitucion::class, 'horario_institucion_id');
    }

    /**
     * Filtra las asignaciones con estado ACTIVO.
     */
    public function scopeActivas($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    /**
     * Filtra las asignaciones con estado INACTIVO.
     */
    public function scopeInactivas($query)
    {
        return $query->where('estado', self::ESTADO_INACTIVO);
    }

    /**
     * Filtra las asignaciones del docente indicado.
     */
    public function scopePorUsuario($query, int $usuarioAppId)
    {
        return $query->where('usuario_app_id', $usuarioAppId);
    }

    /**
     * Filtra las asignaciones de la institución indicada.
     */
    public function scopePorInstitucion($query, int $institucionId)
    {
        return $query->where('institucion_id', $institucionId);
    }

    /**
     * Filtra las asignaciones con el horario institucional indicado.
     */
    public function scopePorHorario($query, int $horarioId)
    {
        return $query->where('horario_institucion_id', $horarioId);
    }

    /**
     * Filtra las asignaciones cuyo cargo coincida con el valor indicado (insensible a mayúsculas).
     */
    public function scopePorCargo($query, string $cargo)
    {
        return $query->where('cargo', mb_strtoupper(trim($cargo)));
    }

    /**
     * Filtra las asignaciones con cargo DOCENTE.
     */
    public function scopeDocentes($query)
    {
        return $query->where('cargo', self::CARGO_DOCENTE);
    }

    /**
     * Filtra las asignaciones con cargo DIRECTOR.
     */
    public function scopeDirectores($query)
    {
        return $query->where('cargo', self::CARGO_DIRECTOR);
    }

    /**
     * Filtra las asignaciones de personal administrativo, auxiliar y de servicio.
     */
    public function scopePersonalAdministrativo($query)
    {
        return $query->whereIn('cargo', [
            self::CARGO_ADMINISTRATIVO,
            self::CARGO_AUXILIAR,
            self::CARGO_PERSONAL_SERVICIO,
        ]);
    }

    /**
     * Filtra las asignaciones vigentes para la fecha indicada (o para hoy si no se especifica).
     *
     * Una asignación es vigente si:
     * - Su estado es ACTIVO.
     * - fecha_inicio es nula o anterior o igual a la fecha indicada.
     * - fecha_fin es nula o estrictamente posterior a la fecha indicada (fecha_fin exclusiva).
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
                    ->orWhereDate('fecha_fin', '>', $fecha); // fecha_fin es exclusiva
            });
    }

    /**
     * Filtra las asignaciones cuya fecha_fin ya ha pasado (vínculo concluido).
     */
    public function scopeFinalizadas($query)
    {
        $hoy = today();

        return $query->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<', $hoy);
    }

    /**
     * Filtra las asignaciones que aún no han iniciado (fecha_inicio en el futuro).
     */
    public function scopeFuturas($query)
    {
        $hoy = today();

        return $query->whereNotNull('fecha_inicio')
            ->whereDate('fecha_inicio', '>', $hoy);
    }

    /**
     * Filtra las asignaciones activas que vencerán dentro del número de días indicado.
     */
    public function scopeProximasAVencer($query, int $dias = 30)
    {
        $hoy = today();
        $limite = today()->addDays($dias);

        return $query->where('estado', self::ESTADO_ACTIVO)
            ->whereNotNull('fecha_fin')
            ->whereBetween('fecha_fin', [$hoy, $limite]);
    }

    /**
     * Retorna la duración de la asignación en días. Retorna null si alguna fecha está ausente.
     */
    public function getDuracionDiasAttribute(): ?int
    {
        if (!$this->fecha_inicio || !$this->fecha_fin) {
            return null;
        }

        return $this->fecha_inicio->diffInDays($this->fecha_fin);
    }

    /**
     * Retorna los días restantes hasta el vencimiento de la asignación.
     * Retorna null si no hay fecha_fin. Retorna 0 si la asignación ya venció.
     */
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

    /**
     * Retorna la descripción legible del estado de la asignación ('Activo', 'Inactivo', etc.).
     */
    public function getEstadoFormateadoAttribute(): string
    {
        return match ($this->estado) {
            self::ESTADO_ACTIVO => 'Activo',
            self::ESTADO_INACTIVO => 'Inactivo',
            default => 'Desconocido',
        };
    }

    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    /**
     * Indica si la asignación tiene estado INACTIVO.
     */
    public function estaInactiva(): bool
    {
        return $this->estado === self::ESTADO_INACTIVO;
    }

    /**
     * Indica si la asignación es vigente hoy, considerando estado y fechas de vigencia.
     * Delega en esVigenteEn con la fecha actual.
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

    /**
     * Indica si la asignación finalizó antes de hoy (fecha_fin en el pasado).
     */
    public function haFinalizado(): bool
    {
        if (!$this->fecha_fin) {
            return false;
        }

        return $this->fecha_fin->isBefore(today());
    }

    /**
     * Indica si la asignación todavía no ha comenzado (fecha_inicio en el futuro).
     */
    public function esFutura(): bool
    {
        if (!$this->fecha_inicio) {
            return false;
        }

        return $this->fecha_inicio->isAfter(today());
    }

    /**
     * Indica si la asignación está activa y vencerá dentro del número de días indicado.
     */
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
     * Renueva la asignación extendiendo su fecha_fin el número de meses indicado.
     *
     * Usa copy() para no modificar el objeto Carbon original de fecha_fin.
     * Si no hay fecha_fin, calcula la nueva fecha desde hoy.
     */
    public function renovarPor(int $meses): bool
    {
        $nuevaFechaFin = $this->fecha_fin
            ? $this->fecha_fin->copy()->addMonths($meses)
            : today()->addMonths($meses);

        return $this->extenderHasta($nuevaFechaFin);
    }

    /**
     * Retorna los estados disponibles para validación en formularios.
     */
    public static function getEstadosDisponibles(): array
    {
        return [
            self::ESTADO_ACTIVO,
            self::ESTADO_INACTIVO,
        ];
    }

    /**
     * Retorna los estados con sus etiquetas legibles para mostrar en selectores.
     */
    public static function getEstadosConEtiquetas(): array
    {
        return [
            self::ESTADO_ACTIVO => 'Activo',
            self::ESTADO_INACTIVO => 'Inactivo',
        ];
    }

    /**
     * Retorna la representación textual de la asignación para los registros de auditoría.
     */
    protected function getNombreAuditable(): string
    {
        $usuario = $this->usuario?->nombre_completo ?? 'Usuario';
        $institucion = $this->institucion?->nombre ?? 'Institución';

        return "{$usuario}  {$institucion} ({$this->cargo})";
    }
}