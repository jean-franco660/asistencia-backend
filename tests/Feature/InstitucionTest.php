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
    public function test_director_solo_ve_sus_instituciones()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucionAsignada = $this->createInstitucion(['nombre' => 'Mi Institución']);
        $this->createInstitucion(['nombre' => 'Otra Institución']);

        $director->instituciones()->attach($institucionAsignada->id);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/web/instituciones');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Mi Institución');
    }

    /** @test */
    public function test_endpoint_instituciones_mias_devuelve_instituciones_director()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucion1 = $this->createInstitucion();
        $institucion2 = $this->createInstitucion();

        $director->instituciones()->attach([$institucion1->id, $institucion2->id]);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/web/instituciones/mias');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function test_endpoint_instituciones_mias_no_devuelve_404()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucion = $this->createInstitucion();
        $director->instituciones()->attach($institucion->id);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/web/instituciones/mias');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function test_admin_puede_crear_institucion()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/instituciones', [
            'nombre' => 'Nueva Institución',
            'direccion' => 'Calle 123',
            'telefono' => '123456789',
            'email' => 'institucion@test.com',
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
