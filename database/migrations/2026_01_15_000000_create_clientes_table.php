<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id('cliente_id');
            $table->char('dni', 8)->nullable()->unique();
            $table->string('nombres', 80);
            $table->string('apellidos', 80);
            $table->char('celular', 9)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->char('ruc', 11)->nullable()->unique();
            $table->string('direccion_fiscal', 200)->nullable();
            $table->string('referencias', 250)->nullable();
            $table->dateTime('creado_en')->useCurrent();
            $table->dateTime('actualizado_en')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
