<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioApp;
use App\Models\Institucion;

class UsuarioAppTest extends TestCase
{
    /** @test */
    public function test_login_docente_exitoso()
    {
        $docente = $this->createUsuarioApp([
            'codigo_modular_docente' => 'DOC123',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/app/login', [
            'codigo_modular_docente' => 'DOC123',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'usuario',
                'token',
            ]);
    }

    /** @test */
    public function test_login_docente_falla_con_credenciales_invalidas()
    {
        $response = $this->postJson('/api/v1/app/login', [
            'codigo_modular_docente' => 'NOEXISTE',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_crear_docente_y_asignar_instituciones()
    {
        $this->actingAsAdmin();
        $institucion1 = $this->createInstitucion();
        $institucion2 = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/usuarios-app', [
            'codigo_modular_docente' => 'DOCTEST',
            'apellido_paterno' => 'APELLIDO',
            'apellido_materno' => 'MATERNO',
            'nombres' => 'DOCENTE',
            'sexo' => 'M',
            'cargo' => 'DOCENTE',
            'password' => 'password123',
            'activo' => true,
            'institucion_ids' => [$institucion1->id, $institucion2->id],
        ]);

        $response->assertStatus(201);

        $docente = UsuarioApp::where('codigo_modular_docente', 'DOCTEST')->first();
        $this->assertCount(2, $docente->instituciones);
    }

    /** @test */
    public function test_admin_puede_listar_todos_los_docentes()
    {
        $this->actingAsAdmin();

        $this->createUsuarioApp();
        $this->createUsuarioApp();
        $this->createUsuarioApp();

        $response = $this->getJson('/api/v1/web/usuarios-app');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function test_supervisor_solo_ve_docentes_de_sus_instituciones()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor', 'estado' => 'autorizado']);
        $institucionAsignada = $this->createInstitucion();
        $institucionNoAsignada = $this->createInstitucion();

        $supervisor->instituciones()->attach($institucionAsignada->id);

        $docente1 = $this->createUsuarioApp();
        $docente2 = $this->createUsuarioApp();

        $docente1->instituciones()->attach($institucionAsignada->id, ['estado' => 'ACTIVO']);
        $docente2->instituciones()->attach($institucionNoAsignada->id, ['estado' => 'ACTIVO']);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/web/usuarios-app');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Solo debe ver 1 docente (el de su institución)
        $this->assertCount(1, $data);
    }

    /** @test */
    public function test_docente_puede_tener_multiples_instituciones()
    {
        $docente = $this->createUsuarioApp();
        $inst1 = $this->createInstitucion();
        $inst2 = $this->createInstitucion();
        $inst3 = $this->createInstitucion();

        $docente->instituciones()->attach($inst1->id, ['estado' => 'ACTIVO']);
        $docente->instituciones()->attach($inst2->id, ['estado' => 'ACTIVO']);
        $docente->instituciones()->attach($inst3->id, ['estado' => 'ACTIVO']);

        $this->assertCount(3, $docente->fresh()->instituciones);
    }

    /** @test */
    public function test_password_es_encriptado_al_crear_docente()
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/web/usuarios-app', [
            'codigo_modular_docente' => 'DOCENCRYPT',
            'apellido_paterno' => 'APELLIDO',
            'apellido_materno' => 'MATERNO',
            'nombres' => 'DOCENTE',
            'sexo' => 'M',
            'cargo' => 'DOCENTE',
            'password' => 'password123',
            'activo' => true,
        ]);

        $docente = UsuarioApp::where('codigo_modular_docente', 'DOCENCRYPT')->first();

        $this->assertNotEquals('password123', $docente->password);
        $this->assertTrue(\Hash::check('password123', $docente->password));
    }
}
