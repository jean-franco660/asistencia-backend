<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Actuar como un usuario administrador autenticado
     */
    protected function actingAsAdmin()
    {
        $admin = UsuarioWeb::create([
            'nombre' => 'Admin Test',
            'email' => 'admin' . uniqid() . '@test.com',
            'password' => 'password123',
            'rol' => 'administrador',
            'estado' => 'autorizado',
        ]);

        return $this->actingAs($admin, 'sanctum');
    }

    /**
     * Actuar como un supervisor autenticado con institución
     */
    protected function actingAsDirector($withInstitution = true)
    {
        return $this->actingAsSupervisor($withInstitution);
    }

    /**
     * Actuar como un supervisor autenticado con institución (nombre actualizado)
     */
    protected function actingAsSupervisor($withInstitution = true)
    {
        $supervisor = UsuarioWeb::create([
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor' . uniqid() . '@test.com',
            'password' => 'password123',
            'rol' => 'supervisor',
            'estado' => 'autorizado',
        ]);

        if ($withInstitution) {
            $institucion = $this->createInstitucion();
            $supervisor->instituciones()->attach($institucion->id);
        }

        return $this->actingAs($supervisor, 'sanctum');
    }

    /**
     * Crear una institución de prueba
     */
    protected function createInstitucion($attributes = [])
    {
        return Institucion::create(array_merge([
            'codigo_modular_ie' => 'CM' . rand(100000, 999999),
            'nombre' => 'Institución ' . uniqid(),
            'distrito' => 'Lima',
            'nivel_educativo' => 'Secundaria',
            'direccion' => 'Dirección Test',
            'latitud' => -12.0464,
            'longitud' => -77.0428,
            'radio' => 100,
        ], $attributes));
    }

    /**
     * Crear un usuario web de prueba
     */
    protected function createUsuarioWeb($attributes = [])
    {
        return UsuarioWeb::create(array_merge([
            'nombre' => 'Usuario ' . uniqid(),
            'email' => 'user' . uniqid() . '@test.com',
            'password' => 'password123',
            'rol' => 'supervisor',
            'estado' => 'pendiente',
        ], $attributes));
    }

    /**
     * Crear un horario de prueba
     */
    protected function createHorario($attributes = [])
    {
        $institucion = $attributes['institucion_id'] ?? $this->createInstitucion()->id;

        return \App\Models\HorarioInstitucion::create(array_merge([
            'institucion_id' => $institucion,
            'nombre_turno' => 'Mañana',
            'hora_entrada' => '08:00',
            'hora_salida' => '13:00',
            'tolerancia_minutos' => 15,
            'dias_semana' => ['L', 'M', 'X', 'J', 'V'],
            'activo' => true,
        ], $attributes));
    }

    /**
     * Crear un usuario app (docente) de prueba
     */
    protected function createUsuarioApp($attributes = [])
    {
        return \App\Models\UsuarioApp::create(array_merge([
            'codigo_modular_docente' => 'DOC' . rand(100000, 999999),
            'apellido_paterno' => 'APELLIDO',
            'apellido_materno' => 'MATERNO',
            'nombres' => 'DOCENTE',
            'sexo' => 'M',
            'estado' => 'ACTIVO',
            'cargo' => 'DOCENTE',
            'password' => 'password123',
            'activo' => true,
        ], $attributes));
    }

    /**
     * Crear un feriado de prueba
     */
    protected function createFeriado($attributes = [])
    {
        return \App\Models\Feriado::create(array_merge([
            'descripcion' => 'Feriado ' . uniqid(),
            'fecha' => now()->addDays(10)->format('Y-m-d'),
            'tipo' => 'nacional',
        ], $attributes));
    }
}
