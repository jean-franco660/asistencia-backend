<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Auditable;

class UsuarioApp extends Authenticatable
{

    use HasFactory, HasApiTokens, Auditable;

    protected $table = 'usuarios_app';

    protected $fillable = [
        'codigo_modular_docente',
        'institucion_id',
        'apellido_paterno',
        'apellido_materno',
        'nombres',
        'sexo',
        'estado',
        'cargo',
        'password',
        'activo',
    ];

    protected $hidden = ['password'];

    protected $appends = ['nombre_completo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // Nombre completo
    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->apellido_paterno} {$this->apellido_materno} {$this->nombres}");
    }

    // Encripta contraseña automáticamente (confirmado)
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = Hash::make($value);
    }

    // Normaliza nombres/apellidos
    public function setApellidoPaternoAttribute($value): void
    {
        $this->attributes['apellido_paterno'] = mb_strtoupper(trim($value));
    }

    public function setApellidoMaternoAttribute($value): void
    {
        $this->attributes['apellido_materno'] = mb_strtoupper(trim($value));
    }

    public function setNombresAttribute($value): void
    {
        $this->attributes['nombres'] = mb_strtoupper(trim($value));
    }

    // Normaliza código modular docente (para login case-insensitive ya lo manejas en controller)
    public function setCodigoModularDocenteAttribute($value): void
    {
        $this->attributes['codigo_modular_docente'] = trim($value);
    }

    // Campo usado para autenticación (Sanctum)
    public function getAuthIdentifierName(): string
    {
        return 'codigo_modular_docente';
    }

    // Many-to-Many con instituciones por pivote docente_institucion (SIN TURNO)
    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'docente_institucion',
            'usuario_app_id',   // FK en pivote hacia usuarios_app.id
            'institucion_id'    // FK en pivote hacia instituciones.id
        )
            ->withPivot('estado', 'fecha_inicio', 'fecha_fin')
            ->withTimestamps();
    }


    public function institucionesActivas(): BelongsToMany
    {
        return $this->instituciones()->wherePivot('estado', 'ACTIVO');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'usuario_app_id');
    }

    public function justificaciones(): HasMany
    {
        return $this->hasMany(Justificacion::class, 'usuario_app_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', 'ACTIVO')->where('activo', true);
    }

    public function scopePersonal($query)
    {
        return $query; // sin filtro por cargo (incluye DOCENTE, DIRECTOR, etc.)
    }

    public function scopePorCargo($query, $cargo)
    {
        return $query->where('cargo', strtoupper(trim($cargo)));
    }

    /**
     * ✅ Filtrar por institución usando el pivote (codigo_modular_ie),
     */
    public function scopePorInstitucion($query, $codigoModularIE)
    {
        $codigo = strtoupper(trim((string) $codigoModularIE));

        return $query->whereHas('instituciones', function ($q) use ($codigo) {
            $q->where('instituciones.codigo_modular_ie', $codigo);
        });
    }

    protected function getNombreAuditable(): string
    {
        return "{$this->nombre_completo} ({$this->codigo_modular_docente})";
    }

}
