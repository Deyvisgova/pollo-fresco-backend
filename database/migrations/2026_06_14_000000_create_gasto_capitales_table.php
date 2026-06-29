<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gasto_capitales')) {
            Schema::create('gasto_capitales', function (Blueprint $table) {
                $table->id('capital_id');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('fondo', 30);
                $table->date('fecha');
                $table->decimal('monto', 10, 2);
                $table->string('descripcion', 200);
                $table->string('nota', 250)->nullable();
                $table->string('estado', 20)->default('ACTIVO');
                $table->timestamp('anulado_en')->nullable();
                $table->unsignedBigInteger('anulado_por')->nullable();
                $table->string('motivo_anulacion', 250)->nullable();
                $table->timestamp('creado_en')->nullable();

                $table->index(['fondo', 'fecha'], 'idx_gasto_capital_fondo_fecha');
                $table->index(['estado', 'fecha'], 'idx_gasto_capital_estado_fecha');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gasto_capitales')) {
            Schema::drop('gasto_capitales');
        }
    }
};
