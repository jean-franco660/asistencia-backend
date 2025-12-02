#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\UsuarioWeb;

echo "=== PRUEBA DEL ENDPOINT /me ===\n\n";

// Obtener un director para simular la petición
$director = UsuarioWeb::where('rol', 'director')
    ->where('estado', 'autorizado')
    ->first();

if (!$director) {
    echo "❌ No hay directores autorizados en el sistema.\n";
    exit;
}

echo "👤 Simulando petición /me para: {$director->nombre}\n";
echo "   Email: {$director->email}\n\n";

// Cargar instituciones
$instituciones = $director->instituciones()
    ->select('instituciones.id', 'instituciones.nombre')
    ->get();

echo "📊 Respuesta del endpoint /me:\n";
echo json_encode([
    'id' => $director->id,
    'nombre' => $director->nombre,
    'email' => $director->email,
    'rol' => $director->rol,
    'estado' => $director->estado,
    'instituciones' => $instituciones->toArray(),
], JSON_PRETTY_PRINT) . "\n\n";

if ($instituciones->isEmpty()) {
    echo "⚠️  ADVERTENCIA: Este director NO tiene instituciones asignadas.\n";
    echo "   El frontend no podrá mostrar ninguna institución.\n\n";
    echo "🔧 Solución:\n";
    echo "   1. Ir al panel de admin\n";
    echo "   2. Editar el director\n";
    echo "   3. Asignar una institución\n";
} else {
    echo "✅ El director tiene " . $instituciones->count() . " institución(es) asignada(s).\n";
    echo "   El frontend debería mostrarlas correctamente.\n";
}
