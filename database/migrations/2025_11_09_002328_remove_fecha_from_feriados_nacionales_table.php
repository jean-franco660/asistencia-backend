<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('feriados_nacionales', function (Blueprint $table) {
            $table->dropColumn('fecha');
        });
    }

    public function down()
    {
        Schema::table('feriados_nacionales', function (Blueprint $table) {
            $table->date('fecha')->nullable();
        });
    }
};