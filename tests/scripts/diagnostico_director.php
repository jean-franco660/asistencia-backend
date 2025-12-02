#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\UsuarioWeb;

echo "=== DIAGNÓSTICO DE DIRECTORES ===\n\n";

// Obtener todos los directores
$directores = UsuarioWeb::where('rol', 'director')->get();

if ($directores->isEmpty()) {
    echo "❌ No hay directores registrados en el sistema.\n";
    exit;
}

echo "📋 Directores encontrados: " . $directores->count() . "\n\n";

foreach ($directores as $director) {
    echo str_repeat("=", 60) . "\n";
    echo "👤 Director: {$director->nombre}\n";
    echo "   Email: {$director->email}\n";
    echo "   Estado: {$director->estado}\n";
    echo "   ID: {$director->id}\n\n";

    // Verificar instituciones asignadas
    $instituciones = $director->instituciones;

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
echo "Total directores: " . $directores->count() . "\n";
echo "Con instituciones: " . $directores->filter(fn($d) => $d->instituciones->isNotEmpty())->count() . "\n";
echo "Sin instituciones: " . $directores->filter(fn($d) => $d->instituciones->isEmpty())->count() . "\n";
