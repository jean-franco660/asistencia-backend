<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Asistencia;
use App\Services\AsistenciaService;
use Carbon\Carbon;

echo "🔍 Verificando constantes de Asistencia...\n\n";

$service = new AsistenciaService();
$horario = (object) [
    'hora_entrada' => '08:00:00',
    'hora_salida' => '17:00:00',
    'tolerancia_minutos' => 15,
];

// Test 1: Entrada a tiempo
$fecha = Carbon::parse('2025-01-15 08:10:00');
$resultado = $service->calcularEstado($fecha, Asistencia::TIPO_ENTRADA, $horario);
echo "✅ Test 1: calcularEstado() para entrada a tiempo\n";
echo "   Esperado: " . Asistencia::RESULTADO_A_TIEMPO . "\n";
echo "   Obtenido: $resultado\n";
echo "   " . ($resultado === Asistencia::RESULTADO_A_TIEMPO ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 2: Entrada tarde
$fecha = Carbon::parse('2025-01-15 08:20:00');
$resultado = $service->calcularEstado($fecha, Asistencia::TIPO_ENTRADA, $horario);
echo "✅ Test 2: calcularEstado() para entrada tarde\n";
echo "   Esperado: " . Asistencia::RESULTADO_TARDE . "\n";
echo "   Obtenido: $resultado\n";
echo "   " . ($resultado === Asistencia::RESULTADO_TARDE ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 3: Salida anticipada
$fecha = Carbon::parse('2025-01-15 16:00:00');
$resultado = $service->calcularEstado($fecha, Asistencia::TIPO_SALIDA, $horario);
echo "✅ Test 3: calcularEstado() para salida anticipada\n";
echo "   Esperado: " . Asistencia::RESULTADO_SALIDA_ANTES . "\n";
echo "   Obtenido: $resultado\n";
echo "   " . ($resultado === Asistencia::RESULTADO_SALIDA_ANTES ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 4: Salida a tiempo
$fecha = Carbon::parse('2025-01-15 17:05:00');
$resultado = $service->calcularEstado($fecha, Asistencia::TIPO_SALIDA, $horario);
echo "✅ Test 4: calcularEstado() para salida a tiempo\n";
echo "   Esperado: " . Asistencia::RESULTADO_A_TIEMPO . "\n";
echo "   Obtenido: $resultado\n";
echo "   " . ($resultado === Asistencia::RESULTADO_A_TIEMPO ? "✅ PASS" : "❌ FAIL") . "\n\n";

echo "🎉 Verificación completada\n";
