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
     * Actuar como un usuario admin autenticado
     */
    protected function actingAsAdmin()
    {
        $admin = UsuarioWeb::create([
            'nombre' => 'Admin Test',
            'email' => 'admin' . uniqid() . '@test.com',
            'password' => 'password123',
            'rol' => 'admin',
            'estado' => 'autorizado',
        ]);

        return $this->actingAs($admin, 'sanctum');
    }

    /**
     * Actuar como un director autenticado con institución
     */
    protected function actingAsDirector($withInstitution = true)
    {
        $director = UsuarioWeb::create([
            'nombre' => 'Director Test',
            'email' => 'director' . uniqid() . '@test.com',
            'password' => 'password123',
            'rol' => 'director',
            'estado' => 'autorizado',
        ]);

        if ($withInstitution) {
            $institucion = $this->createInstitucion();
            $director->instituciones()->attach($institucion->id);
        }

        return $this->actingAs($director, 'sanctum');
    }

    /**
     * Crear una institución de prueba
     */
    protected function createInstitucion($attributes = [])
    {
        return Institucion::create(array_merge([
            'nombre' => 'Institución ' . uniqid(),
            'direccion' => 'Dirección Test',
            'telefono' => '123456789',
            'email' => 'inst' . uniqid() . '@test.com',
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
            'rol' => 'director',
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
            'nombre' => 'Docente ' . uniqid(),
            'email' => 'docente' . uniqid() . '@test.com',
            'password' => 'password123',
            'codigo' => 'DOC' . rand(1000, 9999),
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
