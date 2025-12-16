<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\UsuarioApp;
use App\Models\Asistencia;
use Illuminate\Support\Facades\Artisan;

class PerformanceTest extends TestCase
{
    /**
     * Test de memoria para consultas masivas
     * @group performance
     */
    public function test_consulta_asistencias_uso_memoria()
    {
        $this->actingAsAdmin();

        $memoryBefore = memory_get_usage(true) / 1024 / 1024; // MB

        // Consultar asistencias
        $response = $this->getJson('/api/v1/web/asistencias?per_page=100');

        $memoryAfter = memory_get_usage(true) / 1024 / 1024; // MB
        $memoryUsed = $memoryAfter - $memoryBefore;

        $response->assertStatus(200);
        $this->assertLessThan(50, $memoryUsed, 'Uso de memoria < 50MB para 100 registros');

        dump("💾 Memory Performance Test:");
        dump("  - Memoria antes: " . round($memoryBefore, 2) . "MB");
        dump("  - Memoria después: " . round($memoryAfter, 2) . "MB");
        dump("  - Memoria usada: " . round($memoryUsed, 2) . "MB");
        dump("  - ✅ Test: PASS");
    }

    /**
     * Test de queries N+1 optimizado
     * @group performance
     */
    public function test_sin_queries_n_plus_1()
    {
        $this->actingAsAdmin();

        // Crear datos de prueba
        $institucion = $this->createInstitucion();
        $docentes = UsuarioApp::factory()->count(5)->create();

        foreach ($docentes as $docente) {
            $docente->instituciones()->attach($institucion->id, [
                'estado' => 'ACTIVO',
            ]);
        }

        // Contar queries
        \DB::enableQueryLog();

        $response = $this->getJson('/api/v1/web/usuarios-app');

        $queries = \DB::getQueryLog();
        $queryCount = count($queries);

        \DB::disableQueryLog();

        $response->assertStatus(200);

        // con eager loading correcto debería ser < 8 queries
        $this->assertLessThan(8, $queryCount, 'Evitar N+1: queries < 8 para 5 docentes');

        dump("📊 N+1 Query Test:");
        dump("  - Total queries: {$queryCount}");
        dump("  - Docentes cargados: 5");
        dump("  - Promedio: " . round($queryCount / 5, 2) . " queries/docente");
        dump("  - ✅ Test: " . ($queryCount < 8 ? "PASS" : "FAIL"));
    }

    /**
     * Test de throughput de escritura en BD
     * @group performance
     */
    public function test_throughput_escritura_bd()
    {
        $startTime = microtime(true);
        $created = 0;

        // Test: Crear 100 registros simples de UsuarioApp
        for ($i = 0; $i < 100; $i++) {
            try {
                $usuario = UsuarioApp::create([
                    'codigo_modular_docente' => 'PERF' . str_pad($i, 5, '0', STR_PAD_LEFT),
                    'apellido_paterno' => 'Test',
                    'apellido_materno' => 'Performance',
                    'nombres' => 'Usuario ' . $i,
                    'sexo' => $i % 2 === 0 ? 'M' : 'F',
                    'cargo' => 'DOCENTE',
                    'estado' => 'ACTIVO',
                    'password' => 'test123',
                    'activo' => true,
                ]);

                if ($usuario) {
                    $created++;
                }
            } catch (\Exception $e) {
                // Continuar
                continue;
            }
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $throughput = $created > 0 ? ($created / $duration) * 1000 : 0;

        dump("⚡ Throughput Test (Escritura BD):");
        dump("  - Creados: {$created}/100");
        dump("  - Tiempo: {$duration}ms");
        dump("  - Throughput: " . round($throughput, 2) . " reg/seg");
        dump("  - ✅ Test: " . ($throughput > 50 ? "PASS" : "FAIL"));

        $this->assertGreaterThan(50, $created, 'Al menos 50% creados exitosamente');
        $this->assertGreaterThan(50, $throughput, 'Throughput > 50 reg/seg');
    }
}
