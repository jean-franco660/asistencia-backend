<?php

namespace Tests\Feature;

use App\Models\Institucion;
use App\Models\UsuarioApp;
use App\Models\UsuarioWeb;
use App\Models\UsuarioAppInstitucion; // Add this import
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupervisorProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed or create roles/permissions if necessary
        // For now, assuming basic auth is enough or we act as super admin
        // $this->actingAsSuperAdmin();
    }

    protected function actingAsSuperAdmin()
    {
        $user = UsuarioWeb::factory()->create([
            'rol' => UsuarioWeb::ROL_SUPER_ADMIN,
            'estado' => UsuarioWeb::ESTADO_AUTORIZADO,
            'email' => 'admin@test.com',
        ]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_search_can_find_user_by_code_and_name()
    {
        $userApp = UsuarioApp::factory()->create([
            'codigo_modular' => '1234567',
            'apellido_paterno' => 'PEREZ',
            'apellido_materno' => 'LOPEZ',
            'nombres' => 'JUAN',
        ]);

        // Search by code
        $response = $this->getJson('/api/v1/web/supervisores/provisioning/search?search=1234567');
        $response->assertOk()
            ->assertJsonPath('data.0.id', $userApp->id)
            ->assertJsonPath('data.0.codigo_modular', '1234567');

        // Search by name part
        $response2 = $this->getJson('/api/v1/web/supervisores/provisioning/search?search=PEREZ');
        $response2->assertOk()
            ->assertJsonPath('data.0.id', $userApp->id);
    }

    public function test_show_endpoint_returns_details_and_defaults()
    {
        $userApp = UsuarioApp::factory()->create();
        $inst1 = Institucion::factory()->create();
        $inst2 = Institucion::factory()->create();

        // Assign institutions
        $userApp->instituciones()->attach($inst1->id, ['estado' => 'ACTIVO', 'cargo' => 'DOCENTE']);
        $userApp->instituciones()->attach($inst2->id, ['estado' => 'INACTIVO', 'cargo' => 'DOCENTE']);

        $response = $this->getJson("/api/v1/web/supervisores/provisioning/usuario-app/{$userApp->id}");

        $response->assertOk()
            ->assertJsonPath('usuario_app.id', $userApp->id)
            ->assertJsonPath('has_supervisor_web', false);

        // Check default institutions (only active ones)
        $defaults = $response->json('default_institucion_ids');
        $this->assertContains($inst1->id, $defaults);
        $this->assertNotContains($inst2->id, $defaults);
    }

    public function test_provisioning_creates_supervisor_and_links_institutions()
    {
        $userApp = UsuarioApp::factory()->create([
            'nombres' => 'MARIA',
            'apellido_paterno' => 'GOMEZ',
            'apellido_materno' => 'RUIZ'
        ]);
        $inst = Institucion::factory()->create();
        $userApp->instituciones()->attach($inst->id, ['estado' => 'ACTIVO', 'cargo' => 'DOCENTE']);

        $payload = [
            'usuario_app_id' => $userApp->id,
            'email' => 'maria@test.com',
            'password' => 'password123',
            'institucion_ids' => [$inst->id]
        ];

        $response = $this->postJson('/api/v1/web/supervisores/provisioning', $payload);

        $response->assertStatus(201);

        // Verify DB
        $usuarioWeb = UsuarioWeb::where('email', 'maria@test.com')->first();
        $this->assertNotNull($usuarioWeb);
        $this->assertEquals($userApp->id, $usuarioWeb->usuario_app_id);
        $this->assertEquals(UsuarioWeb::ROL_SUPERVISOR, $usuarioWeb->rol);

        // Verify Check Password (hashed)
        $this->assertTrue(Hash::check('password123', $usuarioWeb->password));

        // Verify Pivot
        $this->assertDatabaseHas('supervisor_institucion', [
            'usuario_web_id' => $usuarioWeb->id,
            'institucion_id' => $inst->id,
            'fecha_inicio' => now()->toDateString(),
        ]);
    }

    public function test_provisioning_fails_if_duplicate_email()
    {
        UsuarioWeb::factory()->create(['email' => 'exists@test.com']);
        $userApp = UsuarioApp::factory()->create();

        $payload = [
            'usuario_app_id' => $userApp->id,
            'email' => 'exists@test.com',
            'password' => '123456',
        ];

        $this->postJson('/api/v1/web/supervisores/provisioning', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_provisioning_fails_if_usuario_app_already_has_supervisor()
    {
        $userApp = UsuarioApp::factory()->create();
        UsuarioWeb::factory()->create(['usuario_app_id' => $userApp->id]);

        $payload = [
            'usuario_app_id' => $userApp->id,
            'email' => 'new@test.com',
            'password' => '123456',
        ];

        // Based on controller, this is validation error on usuario_app_id
        $this->postJson('/api/v1/web/supervisores/provisioning', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_app_id']);
    }

    public function test_provisioning_validates_institution_ownership()
    {
        $userApp = UsuarioApp::factory()->create();
        $otherInst = Institucion::factory()->create(); // Not assigned to userApp

        $payload = [
            'usuario_app_id' => $userApp->id,
            'email' => 'valid@test.com',
            'password' => '123456',
            'institucion_ids' => [$otherInst->id]
        ];

        $this->postJson('/api/v1/web/supervisores/provisioning', $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['institucion_ids' => ['Se intentó asignar instituciones que no están vinculadas al usuario de la app.']]);
    }
}
