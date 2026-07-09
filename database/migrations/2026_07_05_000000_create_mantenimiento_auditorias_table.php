<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mantenimiento_auditorias', function (Blueprint $table) {
            $table->bigIncrements('mantenimiento_auditoria_id');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('usuario', 120)->nullable();
            $table->string('accion', 50);
            $table->string('archivo', 255)->nullable();
            $table->string('estado', 20)->default('completado');
            $table->text('detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index(['accion', 'creado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenimiento_auditorias');
    }
};
