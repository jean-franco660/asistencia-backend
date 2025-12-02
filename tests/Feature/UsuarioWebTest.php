<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

class UsuarioWebTest extends TestCase
{
    /** @test */
    public function test_crear_director_requiere_institucion_id()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Director Test',
            'email' => 'director@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'director',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['institucion_id']);
    }

    /** @test */
    public function test_crear_director_con_institucion_exitoso()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Director Test',
            'email' => 'director@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'director',
            'institucion_id' => $institucion->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rol', 'director');

        $director = UsuarioWeb::where('email', 'director@test.com')->first();
        $this->assertTrue($director->instituciones->contains($institucion));
    }

    /** @test */
    public function test_director_creado_tiene_estado_pendiente()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Director Test',
            'email' => 'director@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'director',
            'institucion_id' => $institucion->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', 'pendiente');
    }

    /** @test */
    public function test_admin_creado_tiene_estado_autorizado()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Admin Test',
            'email' => 'newadmin@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'admin',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', 'autorizado');
    }

    /** @test */
    public function test_autorizar_director_cambia_estado()
    {
        $this->actingAsAdmin();
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'pendiente']);

        $response = $this->postJson("/api/v1/web/usuarios-web/autorizar/{$director->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.estado', 'autorizado');

        $this->assertDatabaseHas('usuarios_web', [
            'id' => $director->id,
            'estado' => 'autorizado',
        ]);
    }

    /** @test */
    public function test_password_es_encriptado_al_crear_usuario()
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Admin Test',
            'email' => 'testadmin@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'admin',
        ]);

        $usuario = UsuarioWeb::where('email', 'testadmin@test.com')->first();

        $this->assertNotEquals('password123', $usuario->password);
        $this->assertTrue(\Hash::check('password123', $usuario->password));
    }
}
