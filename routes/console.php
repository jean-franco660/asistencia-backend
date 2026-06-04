<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Genera registros de FALTA diariamente a las 23:30 (hora Lima) para los docentes
// que tenían horario activo pero no registraron ninguna marcación durante el día
Schedule::command('asistencias:generar-faltas')
    ->dailyAt('23:30')
    ->timezone('America/Lima');
