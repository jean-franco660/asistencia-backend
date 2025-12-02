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
            'rol' => 'admin',
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
    public function test_director_no_autorizado_no_puede_iniciar_sesion()
    {
        UsuarioWeb::create([
            'nombre' => 'Director Test',
            'email' => 'director@test.com',
            'password' => 'password123',
            'rol' => 'director',
            'estado' => 'pendiente',
        ]);

        $response = $this->postJson('/api/v1/web/login', [
            'email' => 'director@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Tu cuenta aún no ha sido autorizada']);
    }

    /** @test */
    public function test_director_autorizado_recibe_sus_instituciones_en_login()
    {
        $director = UsuarioWeb::create([
            'nombre' => 'Director Test',
            'email' => 'director@test.com',
            'password' => 'password123',
            'rol' => 'director',
            'estado' => 'autorizado',
        ]);

        $institucion = $this->createInstitucion(['nombre' => 'Colegio Test']);
        $director->instituciones()->attach($institucion->id);

        $response = $this->postJson('/api/v1/web/login', [
            'email' => 'director@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.instituciones.0.nombre', 'Colegio Test');
    }

    /** @test */
    public function test_endpoint_me_devuelve_datos_usuario_con_instituciones()
    {
        $director = UsuarioWeb::create([
            'nombre' => 'Director Test',
            'email' => 'director@test.com',
            'password' => 'password123',
            'rol' => 'director',
            'estado' => 'autorizado',
        ]);

        $institucion = $this->createInstitucion();
        $director->instituciones()->attach($institucion->id);

        $response = $this->actingAs($director, 'sanctum')
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
            ->assertJsonPath('rol', 'director')
            ->assertJsonCount(1, 'instituciones');
    }

    /** @test */
    public function test_endpoint_me_admin_no_devuelve_instituciones()
    {
        $admin = UsuarioWeb::create([
            'nombre' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'rol' => 'admin',
            'estado' => 'autorizado',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/web/me');

        $response->assertStatus(200)
            ->assertJsonPath('rol', 'admin')
            ->assertJsonPath('instituciones', []);
    }
}
