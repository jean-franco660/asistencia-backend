<?php

use App\Models\UsuarioApp;
use App\Models\Institucion;

echo "=== Test de Relación Docente-Institución ===\n\n";

// 1. Verificar que existen docentes
$totalDocentes = UsuarioApp::count();
echo "Total docentes en BD: {$totalDocentes}\n\n";

if ($totalDocentes === 0) {
    echo "⚠️ No hay docentes en la base de datos.\n";
    echo "   Importa docentes primero.\n";
    exit;
}

// 2. Verificar docentes con institucion_id asignado
$docentesConInstitucion = UsuarioApp::whereNotNull('institucion_id')->count();
$docentesSinInstitucion = UsuarioApp::whereNull('institucion_id')->count();

echo "Docentes con institución principal asignada: {$docentesConInstitucion}\n";
echo "Docentes sin institución principal: {$docentesSinInstitucion}\n\n";

// 3. Verificar relaciones en tabla pivote
$relacionesPivote = DB::table('docente_institucion')->count();
echo "Total relaciones en docente_institucion: {$relacionesPivote}\n\n";

// 4. Probar con un docente específico
$docente = UsuarioApp::with('instituciones')->first();

if (!$docente) {
    echo "⚠️ No se encontró ningún docente.\n";
    exit;
}

echo "=== Ejemplo: Docente #{$docente->id} ===\n";
echo "Código: {$docente->codigo_modular_docente}\n";
echo "Nombre: {$docente->nombres} {$docente->apellido_paterno}\n";
echo "Institución principal ID: " . ($docente->institucion_id ?? 'NULL') . "\n";

// Verificar si tiene institución principal
if ($docente->institucion_id) {
    $institucionPrincipal = Institucion::find($docente->institucion_id);
    if ($institucionPrincipal) {
        echo "Institución principal: {$institucionPrincipal->codigo_modular_ie} - {$institucionPrincipal->nombre}\n";
    }
}

// Verificar relación many-to-many
echo "\nInstituciones relacionadas (tabla pivote):\n";
$instituciones = $docente->instituciones;

if ($instituciones->isEmpty()) {
    echo "  ✗ No tiene instituciones relacionadas en la tabla pivote\n";
} else {
    foreach ($instituciones as $inst) {
        $pivot = $inst->pivot;
        echo "  ✓ {$inst->codigo_modular_ie} - {$inst->nombre}\n";
        echo "    Estado: {$pivot->estado}\n";
        echo "    Fecha inicio: {$pivot->fecha_inicio}\n";
    }
}

echo "\n=== Verificación de Consistencia ===\n";

// Verificar que todos los docentes con institucion_id también tengan la relación en pivote
$inconsistentes = UsuarioApp::whereNotNull('institucion_id')
    ->whereDoesntHave('instituciones', function ($query) {
        $query->whereColumn('instituciones.id', 'usuarios_app.institucion_id');
    })
    ->count();

if ($inconsistentes > 0) {
    echo "⚠️ {$inconsistentes} docentes tienen institucion_id pero NO tienen la relación en pivote\n";
} else {
    echo "✓ Todos los docentes con institucion_id tienen la relación en pivote\n";
}

echo "\n=== Resumen ===\n";
echo "Total docentes: {$totalDocentes}\n";
echo "Con institución principal: {$docentesConInstitucion} (" . round(($docentesConInstitucion / max($totalDocentes, 1)) * 100, 2) . "%)\n";
echo "Relaciones en pivote: {$relacionesPivote}\n";
echo "Inconsistencias: {$inconsistentes}\n";
