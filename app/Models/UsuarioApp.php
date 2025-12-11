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
    use HasFactory, HasApiTokens;

    protected $table = 'usuarios_app';

    protected $fillable = [
        'nombre',
        'codigo',
        'password',
        'activo',
    ];

    protected $hidden = ['password'];

    // Encripta la contraseña automáticamente
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    // Normaliza el código del usuario
    public function setCodigoAttribute($value)
    {
        $this->attributes['codigo'] = strtolower(trim($value));
    }

    // Valor por defecto
    protected $attributes = [
        'activo' => true,
    ];

    public function instituciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Institucion::class,
            'docente_institucion',
            'usuario_app_id',
            'institucion_id'
        )->withTimestamps();
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'usuario_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
