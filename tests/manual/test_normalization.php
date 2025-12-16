<?php

// Simular la función de normalización
function normalizarCodigoInstitucion(string $codigo): string
{
    $codigo = strtoupper(trim($codigo));

    // Si es numérico y tiene menos de 7 dígitos, rellenar con ceros
    if (ctype_digit($codigo) && strlen($codigo) < 7) {
        return str_pad($codigo, 7, '0', STR_PAD_LEFT);
    }

    return $codigo;
}

echo "=== Prueba de Normalización de Códigos ===\n\n";

$casos = [
    '238931' => '0238931',    // 6 dígitos -> 7 dígitos
    '513291' => '0513291',    // 6 dígitos -> 7 dígitos
    '0238931' => '0238931',   // Ya tiene 7 dígitos
    'P210002' => 'P210002',   // Alfanumérico, sin cambios
    '  238931  ' => '0238931', // Con espacios
    'p210002' => 'P210002',   // Minúsculas -> mayúsculas
];

foreach ($casos as $input => $expected) {
    $result = normalizarCodigoInstitucion($input);
    $status = $result === $expected ? '✓' : '✗';
    echo "{$status} '{$input}' -> '{$result}' (esperado: '{$expected}')\n";
}

echo "\n=== Verificar en Base de Datos ===\n\n";

use App\Models\Institucion;

$codigosExcel = ['238931', '513291', '729178', 'P210002'];

foreach ($codigosExcel as $codigoExcel) {
    $codigoNormalizado = normalizarCodigoInstitucion($codigoExcel);
    $existe = Institucion::where('codigo_modular_ie', $codigoNormalizado)->exists();
    $status = $existe ? '✓ ENCONTRADA' : '✗ NO ENCONTRADA';
    echo "{$codigoExcel} -> {$codigoNormalizado}: {$status}\n";
}
