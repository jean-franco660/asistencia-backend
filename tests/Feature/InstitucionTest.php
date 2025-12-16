<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioWeb;
use App\Models\Institucion;

class InstitucionTest extends TestCase
{
    /** @test */
    public function test_admin_puede_ver_todas_las_instituciones()
    {
        $this->actingAsAdmin();

        $this->createInstitucion();
        $this->createInstitucion();
        $this->createInstitucion();

        $response = $this->getJson('/api/v1/web/instituciones');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function test_supervisor_solo_ve_sus_instituciones()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor', 'estado' => 'autorizado']);
        $institucionAsignada = $this->createInstitucion(['nombre' => 'Mi Institución']);
        $this->createInstitucion(['nombre' => 'Otra Institución']);

        $supervisor->instituciones()->attach($institucionAsignada->id);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/web/instituciones');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Mi Institución');
    }

    /** @test */
    public function test_endpoint_instituciones_mias_devuelve_instituciones_supervisor()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor', 'estado' => 'autorizado']);
        $institucion1 = $this->createInstitucion();
        $institucion2 = $this->createInstitucion();

        $supervisor->instituciones()->attach([$institucion1->id, $institucion2->id]);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/web/instituciones/mias');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function test_endpoint_instituciones_mias_no_devuelve_404()
    {
        $supervisor = $this->createUsuarioWeb(['rol' => 'supervisor', 'estado' => 'autorizado']);
        $institucion = $this->createInstitucion();
        $supervisor->instituciones()->attach($institucion->id);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/web/instituciones/mias');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function test_admin_puede_crear_institucion()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/instituciones', [
            'codigo_modular_ie' => 'CM123456',
            'nombre' => 'Nueva Institución',
            'distrito' => 'Lima',
            'direccion' => 'Calle 123',
            'latitud' => -12.0464,
            'longitud' => -77.0428,
            'radio' => 100,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.nombre', 'Nueva Institución');

        $this->assertDatabaseHas('instituciones', [
            'nombre' => 'Nueva Institución',
        ]);
    }
}
