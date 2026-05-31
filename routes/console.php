<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

//  Programar generación automática de faltas
// Se ejecuta todos los días a las 23:30 (11:30 PM)
// Genera registros de FALTA para docentes que tenían horario activo pero no marcaron asistencia
Schedule::command('asistencias:generar-faltas')
    ->dailyAt('23:30')
    ->timezone('America/Lima');
