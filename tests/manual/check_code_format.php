<?php

use App\Models\Institucion;

echo "=== Verificación de Formato de Códigos ===\n\n";

// Buscar instituciones con códigos que empiecen con 0
$conCero = Institucion::whereRaw('codigo_modular_ie LIKE \'0%\'')->take(10)->get(['codigo_modular_ie', 'nombre']);
echo "Instituciones con código que empieza con '0':\n";
foreach ($conCero as $inst) {
    echo "  {$inst->codigo_modular_ie} - {$inst->nombre}\n";
}

echo "\n";

// Buscar instituciones específicas del error
$codigos = ['238931', '0238931', '513291', '0513291'];
echo "Búsqueda de códigos específicos:\n";
foreach ($codigos as $codigo) {
    $existe = Institucion::where('codigo_modular_ie', $codigo)->exists();
    echo "  {$codigo}: " . ($existe ? '✓ Existe' : '✗ No existe') . "\n";
}

echo "\n";

// Ver longitud de códigos
echo "Distribución por longitud de código:\n";
$lengths = Institucion::selectRaw('LENGTH(codigo_modular_ie) as len, COUNT(*) as count')
    ->groupBy('len')
    ->orderBy('len')
    ->get();

foreach ($lengths as $row) {
    echo "  {$row->len} dígitos: {$row->count} instituciones\n";
}
