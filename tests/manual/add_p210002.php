<?php

use App\Models\Institucion;

echo "=== Agregando P210002 Manualmente ===\n\n";

// Verificar si ya existe
if (Institucion::where('codigo_modular_ie', 'P210002')->exists()) {
    echo "⚠️ P210002 ya existe\n";
    exit;
}

// Crear la institución
// IMPORTANTE: Ajusta estos datos según tu Excel
$institucion = Institucion::create([
    'codigo_modular_ie' => 'P210002',
    'nombre' => 'IE P210002',  // CAMBIAR por el nombre real del Excel
    'distrito' => 'Lima',       // CAMBIAR por el distrito real del Excel
    'nivel_educativo' => 'Secundaria',  // CAMBIAR si es necesario
    'radio' => 30,
]);

echo "✓ P210002 creada exitosamente\n";
echo "  ID: {$institucion->id}\n";
echo "  Nombre: {$institucion->nombre}\n";
echo "  Distrito: {$institucion->distrito}\n\n";

echo "Ahora puedes reintentar la importación de docentes.\n";
