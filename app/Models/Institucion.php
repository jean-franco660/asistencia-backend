<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;

class Institucion extends Model
{
    use HasFactory, Auditable;

    protected $table = 'instituciones';

    /* =========================
     * FILLABLE / CASTS
     * ========================= */

    protected $fillable = [
        'codigo_modular_ie',
        'nombre',
        'nivel_educativo',
        'tipo_gestion',
        'departamento',
        'provincia',
        'distrito',
        'centro_poblado',
        'direccion',
        'latitud',
        'longitud',
        'radio',
        'logo',
    ];

    protected $casts = [
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
        'radio' => 'integer',
    ];

    protected $appends = ['logo_url', 'nombre_display'];

    /* =========================
     * RELACIONES
     * ========================= */

    /**
     * Usuarios de la app (docentes, directores) asignados a esta institución
     * CORRECCIÓN: La tabla pivot es 'usuario_app_institucion', no 'docente_institucion'
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioApp::class,
            'usuario_app_institucion',  //  Nombre correcto
            'institucion_id',
            'usuario_app_id'
        )
            ->withPivot([
                'horario_institucion_id',
                'cargo',
                'estado',
                'fecha_inicio',
                'fecha_fin',
            ])
            ->withTimestamps();
    }

    /**
     * Alias para usuarios (más descriptivo para el contexto educativo)
     */
    public function usuariosApp(): BelongsToMany
    {
        return $this->usuarios();
    }

    /**
     * Solo usuarios activos
     */
    public function usuariosActivos(): BelongsToMany
    {
        return $this->usuarios()->wherePivot('estado', 'ACTIVO');
    }

    /**
     * Asignaciones (tabla pivot completa)
     */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(UsuarioAppInstitucion::class, 'institucion_id');
    }

    public function asignacionesActivas(): HasMany
    {
        return $this->asignaciones()->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
    }

    /**
     * Asistencias registradas en esta institución
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'institucion_id');
    }

    /**
     * Supervisores (usuarios_web) asignados a la institución
     */
    public function supervisores(): BelongsToMany
    {
        return $this->belongsToMany(
            UsuarioWeb::class,
            'supervisor_institucion',
            'institucion_id',
            'usuario_web_id'
        )
            ->withPivot(['fecha_inicio', 'fecha_fin'])
            ->withTimestamps();
    }

    /**
     * Supervisores vigentes (considerando fechas)
     */
    public function supervisoresVigentes(): BelongsToMany
    {
        $hoy = now()->toDateString();

        return $this->supervisores()
            ->where(function ($q) use ($hoy) {
                $q->whereNull('supervisor_institucion.fecha_inicio')
                    ->orWhere('supervisor_institucion.fecha_inicio', '<=', $hoy);
            })
            ->where(function ($q) use ($hoy) {
                $q->whereNull('supervisor_institucion.fecha_fin')
                    ->orWhere('supervisor_institucion.fecha_fin', '>=', $hoy);
            });
    }

    /**
     * Horarios de la institución
     */
    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioInstitucion::class);
    }

    public function horariosActivos(): HasMany
    {
        return $this->horarios()->where('activo', true);
    }

    /**
     * Justificaciones de esta institución
     */
    public function justificaciones(): HasMany
    {
        return $this->hasMany(Justificacion::class, 'institucion_id');
    }

    /**
     * Feriados institucionales
     */
    public function feriados(): HasMany
    {
        return $this->hasMany(Feriado::class)->where('tipo', Feriado::TIPO_INSTITUCIONAL);
    }

    /* =========================
     * ACCESSORS
     * ========================= */

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }
        return asset('storage/' . $this->logo);
    }

    /**
     * Retorna un nombre más descriptivo para mostrar en la interfaz.
     * Si el nombre es solo numérico (nomenclatura UGEL), retorna "IE {codigo_modular_ie}".
     * De lo contrario, retorna el nombre original.
     */
    public function getNombreDisplayAttribute(): string
    {
        // Si el nombre es solo numérico (permitiendo espacios), mostrar con prefijo IE y el número (nombre)
        if (preg_match('/^\s*\d+\s*$/', $this->nombre)) {
            return "IE " . trim($this->nombre);
        }
        return $this->nombre;
    }

    /* =========================
     * SCOPES
     * ========================= */

    public function scopePorDistrito($query, string $distrito)
    {
        return $query->where('distrito', $distrito);
    }

    public function scopePorNivel($query, string $nivel)
    {
        return $query->where('nivel_educativo', $nivel);
    }

    public function scopeConCoordenadasCompletas($query)
    {
        return $query->whereNotNull('latitud')
            ->whereNotNull('longitud');
    }

    public function scopeBuscar($query, string $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombre', 'like', "%{$termino}%")
                ->orWhere('codigo_modular_ie', 'like', "%{$termino}%")
                ->orWhere('distrito', 'like', "%{$termino}%")
                ->orWhere('centro_poblado', 'like', "%{$termino}%");
        });
    }

    public function scopeConUsuariosActivos($query)
    {
        return $query->whereHas('asignaciones', function ($q) {
            $q->where('estado', UsuarioAppInstitucion::ESTADO_ACTIVO);
        });
    }

    /* =========================
     * HELPERS
     * ========================= */

    public function tieneCoordenadasCompletas(): bool
    {
        return $this->latitud !== null && $this->longitud !== null;
    }

    /**
     * Calcula la distancia en metros entre dos puntos usando Haversine
     */
    public function calcularDistancia(float $lat, float $lon): float
    {
        if (!$this->tieneCoordenadasCompletas()) {
            return PHP_FLOAT_MAX;
        }

        $earthRadius = 6371000; // metros

        //  Cast explícito a float para satisfacer al analizador estático
        $latitud = (float) $this->latitud;
        $longitud = (float) $this->longitud;

        $dLat = deg2rad($lat - $latitud);
        $dLon = deg2rad($lon - $longitud);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($latitud)) * cos(deg2rad($lat)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Verifica si una coordenada está dentro del rango permitido
     */
    public function estaDentroDelRango(float $lat, float $lon): bool
    {
        $distancia = $this->calcularDistancia($lat, $lon);
        return $distancia <= $this->radio;
    }

    /**
     * Obtiene la distancia formateada (ej: "25.5 m" o "1.2 km")
     */
    public function getDistanciaFormateada(float $lat, float $lon): string
    {
        $distancia = $this->calcularDistancia($lat, $lon);

        if ($distancia >= 1000) {
            return round($distancia / 1000, 2) . ' km';
        }

        return round($distancia, 1) . ' m';
    }

    /**
     * Obtiene el horario activo para un turno específico
     */
    public function getHorarioPorTurno(string $turno): ?HorarioInstitucion
    {
        return $this->horariosActivos()
            ->where('nombre_turno', $turno)
            ->first();
    }

    /**
     * Cuenta usuarios activos por cargo
     */
    public function contarUsuariosPorCargo(string $cargo): int
    {
        return $this->asignacionesActivas()
            ->where('cargo', $cargo)
            ->count();
    }

    /**
     * Obtiene todos los distritos únicos
     */
    public static function getDistritosUnicos(): array
    {
        return static::distinct()
            ->pluck('distrito')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Obtiene todos los niveles educativos únicos
     */
    public static function getNivelesEducativosUnicos(): array
    {
        return static::whereNotNull('nivel_educativo')
            ->distinct()
            ->pluck('nivel_educativo')
            ->sort()
            ->values()
            ->toArray();
    }

    /* =========================
     * AUDITORÍA
     * ========================= */

    protected function getNombreAuditable(): string
    {
        return "{$this->nombre} ({$this->codigo_modular_ie})";
    }
}
