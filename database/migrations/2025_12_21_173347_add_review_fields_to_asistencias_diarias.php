<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('asistencias_diarias', function (Blueprint $table) {
            $table->string('estado_revision', 20)->default('PENDIENTE')->index(); // PENDIENTE, APROBADA, MANTENER_OBSERVADA

            $table->foreignId('revisado_por_usuario_web_id')
                ->nullable()
                ->constrained('usuarios_web');

            $table->timestamp('revisado_en')->nullable();
            $table->text('revision_observacion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asistencias_diarias', function (Blueprint $table) {
            $table->dropForeign(['revisado_por_usuario_web_id']);
            $table->dropColumn([
                'estado_revision',
                'revisado_por_usuario_web_id',
                'revisado_en',
                'revision_observacion'
            ]);
        });
    }
};
