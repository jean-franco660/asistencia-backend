#!/usr/bin/env php
<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\UsuarioWeb;

echo "=== DIAGNÓSTICO DE SUPERVISORES ===\n\n";

// Obtener todos los supervisores
$supervisores = UsuarioWeb::where('rol', 'supervisor')->get();

if ($supervisores->isEmpty()) {
    echo "❌ No hay supervisores registrados en el sistema.\n";
    exit;
}

echo "📋 Supervisores encontrados: " . $supervisores->count() . "\n\n";

foreach ($supervisores as $supervisor) {
    echo str_repeat("=", 60) . "\n";
    echo "👤 Supervisor: {$supervisor->nombre}\n";
    echo "   Email: {$supervisor->email}\n";
    echo "   Estado: {$supervisor->estado}\n";
    echo "   ID: {$supervisor->id}\n\n";

    // Verificar instituciones asignadas
    $instituciones = $supervisor->instituciones;

    if ($instituciones->isEmpty()) {
        echo "   ❌ NO TIENE INSTITUCIONES ASIGNADAS\n";
        echo "   🔧 Solución: Asignar una institución desde el panel de admin\n";
    } else {
        echo "   ✅ Instituciones asignadas: {$instituciones->count()}\n";
        foreach ($instituciones as $inst) {
            echo "      - {$inst->nombre} (ID: {$inst->id})\n";

            // Verificar datos del pivot
            if ($inst->pivot) {
                echo "        Fecha inicio: " . ($inst->pivot->fecha_inicio ?? 'No definida') . "\n";
                echo "        Fecha fin: " . ($inst->pivot->fecha_fin ?? 'Sin fecha fin') . "\n";
            }
        }
    }
    echo "\n";
}

echo str_repeat("=", 60) . "\n";
echo "\n📊 RESUMEN:\n";
echo "Total supervisores: " . $supervisores->count() . "\n";
echo "Con instituciones: " . $supervisores->filter(fn($d) => $d->instituciones->isNotEmpty())->count() . "\n";
echo "Sin instituciones: " . $supervisores->filter(fn($d) => $d->instituciones->isEmpty())->count() . "\n";
