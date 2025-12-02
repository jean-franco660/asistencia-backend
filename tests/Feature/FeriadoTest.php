<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Feriado;
use App\Models\Institucion;

class FeriadoTest extends TestCase
{
    /** @test */
    public function test_crear_feriado_nacional()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/feriados', [
            'descripcion' => 'Día de la Independencia',
            'fecha' => '2025-07-28',
            'tipo' => 'nacional',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('feriados', [
            'descripcion' => 'Día de la Independencia',
            'tipo' => 'nacional',
        ]);
    }

    /** @test */
    public function test_crear_feriado_institucional()
    {
        $this->actingAsAdmin();
        $institucion = $this->createInstitucion();

        $response = $this->postJson('/api/v1/web/feriados', [
            'descripcion' => 'Aniversario Institucional',
            'fecha' => '2025-08-15',
            'tipo' => 'institucional',
            'institucion_id' => $institucion->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('feriados', [
            'descripcion' => 'Aniversario Institucional',
            'tipo' => 'institucional',
            'institucion_id' => $institucion->id,
        ]);
    }

    /** @test */
    public function test_feriado_institucional_requiere_institucion_id()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/feriados', [
            'descripcion' => 'Feriado Test',
            'fecha' => '2025-09-01',
            'tipo' => 'institucional',
            // institucion_id falta
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['institucion_id']);
    }

    /** @test */
    public function test_validacion_de_fecha_feriado()
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/web/feriados', [
            'descripcion' => 'Feriado Test',
            'fecha' => 'invalid-date',
            'tipo' => 'nacional',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha']);
    }

    /** @test */
    public function test_listar_feriados_nacionales()
    {
        $this->actingAsAdmin();

        $this->createFeriado(['tipo' => 'nacional']);
        $this->createFeriado(['tipo' => 'nacional']);
        $this->createFeriado(['tipo' => 'institucional', 'institucion_id' => $this->createInstitucion()->id]);

        $response = $this->getJson('/api/v1/web/feriados?tipo=nacional');

        $response->assertStatus(200);
        $data = $response->json();

        // Solo debe devolver feriados nacionales
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    /** @test */
    public function test_director_puede_crear_feriado_institucional_en_su_institucion()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucion = $this->createInstitucion();
        $director->instituciones()->attach($institucion->id);

        $response = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/web/feriados', [
                'descripcion' => 'Feriado Institucional',
                'fecha' => '2025-10-10',
                'tipo' => 'institucional',
                'institucion_id' => $institucion->id,
            ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_director_no_puede_crear_feriado_en_institucion_no_asignada()
    {
        $director = $this->createUsuarioWeb(['rol' => 'director', 'estado' => 'autorizado']);
        $institucionAsignada = $this->createInstitucion();
        $institucionNoAsignada = $this->createInstitucion();

        $director->instituciones()->attach($institucionAsignada->id);

        $response = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/web/feriados', [
                'descripcion' => 'Feriado Test',
                'fecha' => '2025-11-11',
                'tipo' => 'institucional',
                'institucion_id' => $institucionNoAsignada->id,
            ]);

        $response->assertStatus(403);
    }
}
