<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auditorias_seguridad')) {
            return;
        }

        Schema::create('auditorias_seguridad', function (Blueprint $table) {
            $table->bigIncrements('auditoria_id');
            $table->uuid('evento_uuid')->unique();
            $table->unsignedBigInteger('usuario_id')->nullable()->index();
            $table->string('rol', 30)->nullable();
            $table->string('metodo', 10);
            $table->string('ruta', 255)->index();
            $table->unsignedSmallInteger('estado_http')->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('agente', 500)->nullable();
            $table->timestamp('creado_en')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias_seguridad');
    }
};
