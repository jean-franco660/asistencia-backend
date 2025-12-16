<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
      public function up(): void
      {
            Schema::create('justificaciones', function (Blueprint $table) {
                  $table->id();

                  // 🔹 RELACIONES
                  $table->foreignId('asistencia_id')
                        ->nullable()
                        ->constrained('asistencias')
                        ->onDelete('cascade');

                  $table->foreignId('usuario_app_id')
                        ->constrained('usuarios_app')
                        ->onDelete('cascade');

                  $table->foreignId('institucion_id')
                        ->constrained('instituciones')
                        ->onDelete('cascade');

                  // 🔹 TIPO DE JUSTIFICACIÓN
                  $table->enum('tipo', [
                        'ENFERMEDAD',
                        'PERMISO_PERSONAL',
                        'LICENCIA',
                        'COMISION_SERVICIO',
                        'CAPACITACION',
                        'DUELO',
                        'MATERNIDAD',
                        'PATERNIDAD',
                        'OTRO'
                  ]);

                  // 🔹 FECHAS
                  $table->date('fecha_inicio');

                  $table->date('fecha_fin');

                  // 🔹 DETALLES
                  $table->text('motivo');

                  // 🔹 ESTADO Y APROBACIÓN
                  $table->enum('estado', ['PENDIENTE', 'APROBADO', 'RECHAZADO'])
                        ->default('PENDIENTE');

                  $table->foreignId('usuario_web_id')
                        ->nullable()
                        ->constrained('usuarios_web')
                        ->onDelete('set null')
                        ->comment('Usuario web que revisó la justificación');

                  $table->text('observaciones')
                        ->nullable();

                  $table->timestamp('fecha_revision')
                        ->nullable();

                  $table->timestamps();

                  // 🔹 ÍNDICES
                  $table->index(['usuario_app_id', 'fecha_inicio']);
                  $table->index(['institucion_id', 'estado']);
                  $table->index('estado');
                  $table->index('tipo');
            });
      }

      public function down(): void
      {
            Schema::dropIfExists('justificaciones');
      }
};