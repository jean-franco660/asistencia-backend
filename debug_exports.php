<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Exports\DocenteHistorialExport;
use App\Exports\InstitucionConsolidadoExport;
use App\Exports\ReporteMensualExport;
use App\Models\UsuarioAppInstitucion;
use App\Models\Asistencia;

echo "--- DEBUG EXPORTS SIMPLE ---\n";

// 1. DocenteHistorialExport
echo "1. DocenteHistorialExport: ";
try {
    $export1 = new DocenteHistorialExport([]);
    $count1 = $export1->collection()->count();
    echo "Count = $count1\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 2. InstitucionConsolidadoExport
echo "2. InstitucionConsolidadoExport: ";
try {
    $export2 = new InstitucionConsolidadoExport([]);
    $count2 = $export2->collection()->count();
    echo "Count = $count2\n";
    if ($count2 === 0) {
        $active = UsuarioAppInstitucion::where('estado', 'ACTIVO')->count();
        echo "   (DB has $active ACTIVE assignments)\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 3. ReporteMensualExport
echo "3. ReporteMensualExport: ";
try {
    $export3 = new ReporteMensualExport([]);
    $coll3 = $export3->collection();
    $count3 = $coll3->count();
    echo "Count = $count3\n";

    if ($count3 === 0) {
        $totalAsistencias = Asistencia::count();
        echo "   (DB has $totalAsistencias total Asistencia records)\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
