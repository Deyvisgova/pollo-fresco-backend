<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otros_productos_venta_lotes_consumos')) {
            return;
        }

        Schema::create('otros_productos_venta_lotes_consumos', function (Blueprint $table) {
            $table->id('consumo_id');
            $table->unsignedBigInteger('venta_op_diaria_id');
            $table->unsignedBigInteger('compra_lote_detalle_id');
            $table->decimal('cantidad', 12, 4);
            $table->decimal('costo_unitario', 12, 6);
            $table->decimal('costo_total', 12, 2);
            $table->timestamp('creado_en')->nullable();
            $table->index('venta_op_diaria_id', 'op_consumos_venta_idx');
            $table->index('compra_lote_detalle_id', 'op_consumos_lote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otros_productos_venta_lotes_consumos');
    }
};
