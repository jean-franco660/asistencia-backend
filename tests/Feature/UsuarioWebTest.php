<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

class UsuarioWebTest extends TestCase
{
    /** @test */
    public function test_crear_supervisor_requiere_institucion_id()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'supervisor',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['institucion_id']);
    }

    /** @test */
    public function test_crear_supervisor_con_institucion_exitoso()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'supervisor',
            'institucion_id' => $institucion->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rol', 'supervisor');

        $supervisor = UsuarioWeb::where('email', 'supervisor@test.com')->first();
        $this->assertTrue($supervisor->instituciones->contains($institucion));
    }

    /** @test */
    public function test_supervisor_creado_tiene_estado_pendiente()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Supervisor Test',
            'email' => 'supervisor@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'supervisor',
            'institucion_id' => $institucion->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', 'pendiente');
    }

    /** @test */
    public function test_administrador_creado_tiene_estado_autorizado()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Admin Test',
            'email' => 'newadmin@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'administrador',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', 'autorizado');
    }

    /** @test */
    public function test_autorizar_supervisor_cambia_estado()
    {
        $this->actingAsAdmin();
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor', 'estado' => 'pendiente']);

        $response = $this->postJson("/api/v1/web/usuarios-web/autorizar/{$supervisor->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.estado', 'autorizado');

        $this->assertDatabaseHas('usuarios_web', [
            'id' => $supervisor->id,
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
            'rol' => 'administrador',
        ]);

        $usuario = UsuarioWeb::where('email', 'testadmin@test.com')->first();

        $this->assertNotEquals('password123', $usuario->password);
        $this->assertTrue(\Hash::check('password123', $usuario->password));
    }
    /** @test */
    public function test_supervisor_no_puede_crear_usuarios()
    {
        $this->createSupervisorUser();

        $response = $this->postJson('/api/v1/web/usuarios-web', [
            'nombre' => 'Intruder Supervisor',
            'email' => 'intruder@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'supervisor',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_supervisor_no_puede_autorizar_usuarios()
    {
        $this->createSupervisorUser();
        $targetUser = $this->createUsuarioWeb(['rol' => 'supervisor', 'estado' => 'pendiente']);

        $response = $this->postJson("/api/v1/web/usuarios-web/autorizar/{$targetUser->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_supervisor_no_puede_eliminar_usuarios()
    {
        $this->createSupervisorUser();
        $targetUser = $this->createUsuarioWeb(['rol' => 'supervisor']);

        $response = $this->deleteJson("/api/v1/web/usuarios-web/{$targetUser->id}");

        $response->assertStatus(403);
    }

    /**
     * Helper to act as supervisor
     */
    protected function createSupervisorUser()
    {
        $user = UsuarioWeb::factory()->create(['rol' => 'supervisor', 'estado' => 'autorizado']);
        $this->actingAs($user);
        return $user;
    }
}
