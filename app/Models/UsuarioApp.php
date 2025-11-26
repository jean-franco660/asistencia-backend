<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens; 

class UsuarioApp extends Authenticatable 
{
    use HasFactory;
    use HasApiTokens; 

    protected $table = 'usuarios_app';

    protected $fillable = [
        'nombre',
        'codigo',
        'password',
        'activo',
    ];

    // Ocultar contraseña y otros datos sensibles
    protected $hidden = ['password'];

    // Mutator para encriptar contraseña automáticamente
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    // Relación: un docente puede estar en muchas instituciones
    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'docente_institucion',
            'usuario_app_id',
            'institucion_id'
        )->withTimestamps();
    }

    // Relación: un docente tiene muchas asistencias
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'usuario_id');
    }

    // Scope para docentes activos
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}