<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feriados', function (Blueprint $table) {
            $table->string('descripcion')->after('mes');
        });
    }

    public function down(): void
    {
        Schema::table('feriados', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });
    }
};
