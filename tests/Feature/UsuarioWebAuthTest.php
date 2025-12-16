<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

class UsuarioWebAuthTest extends TestCase
{
    /** @test */
    public function test_login_exitoso_con_credenciales_validas()
    {
        $usuario = UsuarioWeb::create([
            'nombre' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'rol' => 'administrador',
            'estado' => 'autorizado',
        ]);

        $response = $this->postJson('/api/v1/web/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'user' => ['id', 'nombre', 'email', 'rol', 'estado', 'instituciones'],
                'token',
            ]);
    }

    /** @test */
    public function test_login_falla_con_credenciales_invalidas()
    {
        $response = $this->postJson('/api/v1/web/login', [
            'email' => 'noexiste@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Credenciales inválidas']);
    }

    /** @test */
    public function test_supervisor_no_autorizado_no_puede_iniciar_sesion()
    {
        UsuarioWeb::create([
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor@test.com',
            'password' => 'password123',
            'rol' => 'supervisor',
            'estado' => 'pendiente',
        ]);

        $response = $this->postJson('/api/v1/web/login', [
            'email' => 'supervisor@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Tu cuenta aún no ha sido autorizada']);
    }

    /** @test */
    public function test_supervisor_autorizado_recibe_sus_instituciones_en_login()
    {
        $supervisor = UsuarioWeb::create([
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor@test.com',
            'password' => 'password123',
            'rol' => 'supervisor',
            'estado' => 'autorizado',
        ]);

        $institucion = $this->createInstitucion(['nombre' => 'Colegio Test']);
        $supervisor->instituciones()->attach($institucion->id);

        $response = $this->postJson('/api/v1/web/login', [
            'email' => 'supervisor@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.instituciones.0.nombre', 'Colegio Test');
    }

    /** @test */
    public function test_endpoint_me_devuelve_datos_usuario_con_instituciones()
    {
        $supervisor = UsuarioWeb::create([
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor@test.com',
            'password' => 'password123',
            'rol' => 'supervisor',
            'estado' => 'autorizado',
        ]);

        $institucion = $this->createInstitucion();
        $supervisor->instituciones()->attach($institucion->id);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/web/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'nombre',
                'email',
                'rol',
                'estado',
                'instituciones',
            ])
            ->assertJsonPath('rol', 'supervisor')
            ->assertJsonCount(1, 'instituciones');
    }

    /** @test */
    public function test_endpoint_me_administrador_no_devuelve_instituciones()
    {
        $admin = UsuarioWeb::create([
            'nombre' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'rol' => 'administrador',
            'estado' => 'autorizado',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/web/me');

        $response->assertStatus(200)
            ->assertJsonPath('rol', 'administrador')
            ->assertJsonPath('instituciones', []);
    }
}
