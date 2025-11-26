<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('feriados_nacionales', function (Blueprint $table) {
            $table->integer('dia')->after('id');
            $table->integer('mes')->after('dia');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('feriados_nacionales', function (Blueprint $table) {
            $table->dropColumn(['dia', 'mes']);
        });
    }
};
