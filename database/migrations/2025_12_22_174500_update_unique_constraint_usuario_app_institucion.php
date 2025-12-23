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
        Schema::table('usuario_app_institucion', function (Blueprint $table) {
            // Drop the old strict unique constraint (User + Institution)
            $table->dropUnique('uq_usuario_app_institucion');

            // Add new flexible unique constraint (User + Institution + Shift)
            // This allows multiple rows for the same institution if shifts are different
            $table->unique(['usuario_app_id', 'institucion_id', 'horario_institucion_id'], 'uq_user_ie_schedule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuario_app_institucion', function (Blueprint $table) {
            $table->dropUnique('uq_user_ie_schedule');
            $table->unique(['usuario_app_id', 'institucion_id'], 'uq_usuario_app_institucion');
        });
    }
};
