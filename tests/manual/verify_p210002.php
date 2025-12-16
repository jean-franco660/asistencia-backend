<?php

use App\Models\Institucion;

echo "=== Verificación de P210002 ===\n\n";

$p210002 = Institucion::where('codigo_modular_ie', 'P210002')->first();

if ($p210002) {
    echo "✓ P210002 ENCONTRADA\n";
    echo "  Nombre: {$p210002->nombre}\n";
    echo "  Distrito: {$p210002->distrito}\n";
} else {
    echo "✗ P210002 NO ENCONTRADA\n";
    echo "  Esta institución NO fue importada.\n";
    echo "  Fue rechazada por la validación anterior.\n\n";
    echo "SOLUCIÓN:\n";
    echo "  1. Agregar manualmente P210002, O\n";
    echo "  2. Re-importar archivo de instituciones completo\n";
}

echo "\n";
echo "Total instituciones: " . Institucion::count() . "\n";
echo "Alfanuméricas: " . Institucion::whereRaw('codigo_modular_ie NOT REGEXP \'^[0-9]{7}$\'')->count() . "\n";
