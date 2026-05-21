<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use App\Traits\Auditable;

class HorarioInstitucion extends Model
{
    use HasFactory, Auditable;

    protected $table = 'horarios_institucion';

    /* =========================
     * CONSTANTES - TURNOS
     * ========================= */

    public const TURNO_MANANA = 'MAÑANA';
    public const TURNO_TARDE = 'TARDE';
    public const TURNO_NOCHE = 'NOCHE';

    /* =========================
     * CONSTANTES - DÍAS
     * ========================= */

    public const DIA_LUNES = 'L';
    public const DIA_MARTES = 'M';
    public const DIA_MIERCOLES = 'X';
    public const DIA_JUEVES = 'J';
    public const DIA_VIERNES = 'V';
    public const DIA_SABADO = 'S';
    public const DIA_DOMINGO = 'D';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

    protected $fillable = [
        'institucion_id',
        'nombre_turno',
        'hora_entrada',
        'hora_salida',
        'tolerancia_entrada_minutos',
        'tolerancia_salida_minutos',
        'dias_semana',
        'activo',
    ];

    protected $casts = [
        'dias_semana' => 'array',
        'activo' => 'boolean',
        'tolerancia_entrada_minutos' => 'integer',
        'tolerancia_salida_minutos' => 'integer',
    ];

    protected $appends = ['dias_laborales_text'];

    protected $attributes = [
        'activo' => true,
        'tolerancia_entrada_minutos' => 5,
        'tolerancia_salida_minutos' => 5,
    ];

    /* =========================
     * RELACIONES
     * ========================= */

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class);
    }

    /**
     * Usuarios asignados a este horario (por institución)
     */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(
            UsuarioAppInstitucion::class,
            'horario_institucion_id'
        );
    }

    public function asignacionesActivas(): HasMany
    {
        return $this->asignaciones()->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
    }

    /**
     * Asistencias registradas en este horario
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(
            Asistencia::class,
            'horario_institucion_id'
        );
    }

    public function justificaciones(): HasMany
    {
        return $this->hasMany(
            Justificacion::class,
            'horario_institucion_id'
        );
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

    public function scopePorInstitucion($query, int $institucionId)
    {
        return $query->where('institucion_id', $institucionId);
    }

    public function scopePorTurno($query, string $turno)
    {
        return $query->where('nombre_turno', $turno);
    }

    public function scopeTurnoManana($query)
    {
        return $query->where('nombre_turno', self::TURNO_MANANA);
    }

    public function scopeTurnoTarde($query)
    {
        return $query->where('nombre_turno', self::TURNO_TARDE);
    }

    public function scopeTurnoNoche($query)
    {
        return $query->where('nombre_turno', self::TURNO_NOCHE);
    }

    /**
     * Horarios que aplican para un día específico
     */
    public function scopeParaDia($query, string $dia)
    {
        return $query->whereJsonContains('dias_semana', $dia);
    }

    /**
     * Horarios que aplican para hoy
     */
    public function scopeParaHoy($query)
    {
        $diaHoy = static::getDiaAbreviado(now('America/Lima'));
        return $query->whereJsonContains('dias_semana', $diaHoy);
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getHoraEntradaFormateadaAttribute(): string
    {
        return Carbon::parse($this->hora_entrada)->format('H:i');
    }

    public function getHoraSalidaFormateadaAttribute(): string
    {
        return Carbon::parse($this->hora_salida)->format('H:i');
    }

    public function getDuracionHorasAttribute(): float
    {
        $entrada = Carbon::parse($this->hora_entrada);
        $salida = Carbon::parse($this->hora_salida);

        if ($salida->lessThan($entrada)) {
            $salida->addDay();
        }

        return $entrada->diffInMinutes($salida) / 60;
    }

    public function getDiasLaboralesAttribute(): array
    {
        $dias = $this->dias_semana ?? [];
        if (is_string($dias)) {
            return explode(',', $dias);
        }
        return $dias;
    }

    public function getDiasLaboralesTextAttribute(): string
    {
        $dias = $this->dias_semana ?? [];

        // Convertir string a array si es necesario
        if (is_string($dias)) {
            $dias = explode(',', $dias);
        }

        if (empty($dias))
            return 'No especificado';

        // Mapa de días a índices para ordenar y comparar
        $orden = ['L' => 1, 'M' => 2, 'X' => 3, 'J' => 4, 'V' => 5, 'S' => 6, 'D' => 7];

        // Mapeo inverso para nombres cortos
        $nombres = [
            'L' => 'Lun',
            'M' => 'Mar',
            'X' => 'Mié',
            'J' => 'Jue',
            'V' => 'Vie',
            'S' => 'Sáb',
            'D' => 'Dom',
        ];

        // Ordenar los días
        usort($dias, fn($a, $b) => ($orden[$a] ?? 0) <=> ($orden[$b] ?? 0));

        // Verificar si son consecutivos
        $esConsecutivo = true;
        $count = count($dias);

        if ($count > 2) { // Solo vale la pena agrupar si son más de 2 días
            for ($i = 0; $i < $count - 1; $i++) {
                $actual = $orden[$dias[$i]] ?? 0;
                $siguiente = $orden[$dias[$i + 1]] ?? 0;

                if ($siguiente !== $actual + 1) {
                    $esConsecutivo = false;
                    break;
                }
            }

            if ($esConsecutivo) {
                // Si son consecutivos, mostrar rango: "Lun - Vie"
                $primero = $nombres[$dias[0]] ?? $dias[0];
                $ultimo = $nombres[$dias[$count - 1]] ?? $dias[$count - 1];
                return "$primero - $ultimo";
            }
        }

        // Si no son consecutivos o son pocos, mostrar lista separada por comas
        return implode(', ', array_map(fn($d) => $nombres[$d] ?? $d, $dias));
    }

    public function getTurnoFormateadoAttribute(): string
    {
        return match ($this->nombre_turno) {
            self::TURNO_MANANA => 'Mañana',
            self::TURNO_TARDE => 'Tarde',
            self::TURNO_NOCHE => 'Noche',
            default => $this->nombre_turno,
        };
    }

    /* =========================
     * HELPERS DE VALIDACIÓN
     * ========================= */

    /**
     * Verifica si este horario aplica para una fecha específica
     */
    public function aplicaParaFecha(Carbon $fecha): bool
    {
        if (!$this->activo) {
            return false;
        }

        $diaAbreviado = static::getDiaAbreviado($fecha);

        return in_array($diaAbreviado, $this->dias_semana ?? [], true);
    }

    /**
     * Verifica si una hora está dentro del rango con tolerancia (entrada)
     */
    public function estaEnRangoEntrada(Carbon $horaActual): bool
    {
        $horaEntrada = Carbon::parse($this->hora_entrada);
        $horaLimite = $horaEntrada->copy()->addMinutes($this->tolerancia_entrada_minutos);

        return $horaActual->between($horaEntrada, $horaLimite);
    }

    /**
     * Verifica si llegó tarde
     */
    public function esEntradaTarde(Carbon $horaActual): bool
    {
        $horaEntrada = Carbon::parse($this->hora_entrada);
        $horaLimite = $horaEntrada->copy()->addMinutes($this->tolerancia_entrada_minutos);

        return $horaActual->greaterThan($horaLimite);
    }

    /**
     * Verifica si salió antes de tiempo (considerando tolerancia)
     */
    public function esSalidaAnticipada(Carbon $horaActual): bool
    {
        $horaSalida = Carbon::parse($this->hora_salida);
        $horaLimite = $horaSalida->copy()->subMinutes($this->tolerancia_salida_minutos);

        return $horaActual->lessThan($horaLimite);
    }

    /**
     * Calcula minutos de tardanza
     */
    public function calcularMinutosTardanza(Carbon $horaActual): int
    {
        $horaEntrada = Carbon::parse($this->hora_entrada);
        $horaLimite = $horaEntrada->copy()->addMinutes($this->tolerancia_entrada_minutos);

        if ($horaActual->lessThanOrEqualTo($horaLimite)) {
            return 0;
        }

        return $horaActual->diffInMinutes($horaLimite);
    }

    /**
     * Calcula minutos de salida anticipada
     */
    public function calcularMinutosSalidaAnticipada(Carbon $horaActual): int
    {
        $horaSalida = Carbon::parse($this->hora_salida);
        $horaLimite = $horaSalida->copy()->subMinutes($this->tolerancia_salida_minutos);

        if ($horaActual->greaterThanOrEqualTo($horaLimite)) {
            return 0;
        }

        return $horaLimite->diffInMinutes($horaActual);
    }

    /**
     * Determina el resultado de la marcación de entrada
     */
    public function determinarResultadoEntrada(Carbon $horaActual): string
    {
        if ($this->estaEnRangoEntrada($horaActual)) {
            return Asistencia::RESULTADO_A_TIEMPO;
        }

        return Asistencia::RESULTADO_TARDE;
    }

    /**
     * Determina el resultado de la marcación de salida
     */
    public function determinarResultadoSalida(Carbon $horaActual): string
    {
        if ($this->esSalidaAnticipada($horaActual)) {
            return Asistencia::RESULTADO_SALIDA_ANTES;
        }

        return Asistencia::RESULTADO_A_TIEMPO;
    }

    /**
     * Verifica si está activo
     */
    public function estaActivo(): bool
    {
        return $this->activo === true;
    }

    /**
     * Cuenta usuarios asignados a este horario
     */
    public function contarUsuariosAsignados(): int
    {
        return $this->asignacionesActivas()->count();
    }

    /* =========================
     * MÉTODOS DE NEGOCIO
     * ========================= */

    /**
     * Activa el horario
     */
    public function activar(): bool
    {
        $this->activo = true;
        return $this->save();
    }

    /**
     * Desactiva el horario
     */
    public function desactivar(): bool
    {
        $this->activo = false;
        return $this->save();
    }

    /* =========================
     * MÉTODOS ESTÁTICOS
     * ========================= */

    /**
     * Obtiene la abreviación del día según la fecha
     */
    public static function getDiaAbreviado(Carbon $fecha): string
    {
        return match ($fecha->dayOfWeek) {
            0 => self::DIA_DOMINGO,
            1 => self::DIA_LUNES,
            2 => self::DIA_MARTES,
            3 => self::DIA_MIERCOLES,
            4 => self::DIA_JUEVES,
            5 => self::DIA_VIERNES,
            6 => self::DIA_SABADO,
        };
    }

    /**
     * Obtiene todos los turnos disponibles
     */
    public static function getTurnosDisponibles(): array
    {
        return [
            self::TURNO_MANANA,
            self::TURNO_TARDE,
            self::TURNO_NOCHE,
        ];
    }

    /**
     * Obtiene turnos con etiquetas
     */
    public static function getTurnosConEtiquetas(): array
    {
        return [
            self::TURNO_MANANA => 'Mañana',
            self::TURNO_TARDE => 'Tarde',
            self::TURNO_NOCHE => 'Noche',
        ];
    }

    /**
     * Obtiene todos los días disponibles
     */
    public static function getDiasDisponibles(): array
    {
        return [
            self::DIA_LUNES,
            self::DIA_MARTES,
            self::DIA_MIERCOLES,
            self::DIA_JUEVES,
            self::DIA_VIERNES,
            self::DIA_SABADO,
            self::DIA_DOMINGO,
        ];
    }

    /**
     * Obtiene días con etiquetas
     */
    public static function getDiasConEtiquetas(): array
    {
        return [
            self::DIA_LUNES => 'Lunes',
            self::DIA_MARTES => 'Martes',
            self::DIA_MIERCOLES => 'Miércoles',
            self::DIA_JUEVES => 'Jueves',
            self::DIA_VIERNES => 'Viernes',
            self::DIA_SABADO => 'Sábado',
            self::DIA_DOMINGO => 'Domingo',
        ];
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        return "{$this->nombre_turno} ({$this->hora_entrada} - {$this->hora_salida})";
    }
}