<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institucion_feriados', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institucion_id')
                ->constrained('instituciones')
                ->onDelete('cascade');

            $table->unsignedTinyInteger('dia'); // 1-31
            $table->unsignedTinyInteger('mes'); // 1-12
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['institucion_id','dia','mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institucion_feriados');
    }
};
