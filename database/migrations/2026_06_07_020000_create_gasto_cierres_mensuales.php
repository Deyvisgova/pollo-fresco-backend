<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gasto_cierres_mensuales')) {
            Schema::create('gasto_cierres_mensuales', function (Blueprint $table) {
                $table->increments('cierre_id');
                $table->integer('usuario_id');
                $table->string('periodo', 7);
                $table->date('fecha_desde');
                $table->date('fecha_hasta');
                $table->decimal('pollo_ventas', 10, 2)->default(0);
                $table->decimal('pollo_costos', 10, 2)->default(0);
                $table->decimal('pollo_ganancia', 10, 2)->default(0);
                $table->decimal('pollo_gastos', 10, 2)->default(0);
                $table->decimal('pollo_saldo', 10, 2)->default(0);
                $table->decimal('otros_ventas', 10, 2)->default(0);
                $table->decimal('otros_costos', 10, 2)->default(0);
                $table->decimal('otros_ganancia', 10, 2)->default(0);
                $table->decimal('otros_gastos', 10, 2)->default(0);
                $table->decimal('otros_saldo', 10, 2)->default(0);
                $table->string('observacion', 250)->nullable();
                $table->dateTime('cerrado_en')->useCurrent();

                $table->unique('periodo', 'uq_gasto_cierre_periodo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gasto_cierres_mensuales')) {
            Schema::drop('gasto_cierres_mensuales');
        }
    }
};
