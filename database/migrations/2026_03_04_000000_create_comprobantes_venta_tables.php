<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->bigIncrements('comprobante_venta_id');
            $table->unsignedInteger('usuario_id');
            $table->string('tipo_comprobante', 20);
            $table->string('serie', 10);
            $table->string('numero', 20);
            $table->date('fecha_emision');
            $table->string('moneda', 10)->default('PEN');
            $table->string('forma_pago', 40)->default('Contado');
            $table->string('metodo_pago', 30)->default('efectivo');
            $table->string('cliente_tipo_documento', 10)->nullable();
            $table->string('cliente_documento', 20)->nullable();
            $table->string('cliente_nombre', 150)->nullable();
            $table->string('cliente_direccion', 255)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('monto_recibido', 12, 2)->nullable();
            $table->decimal('vuelto', 12, 2)->default(0);
            $table->string('referencia_serie', 10)->nullable();
            $table->string('referencia_numero', 20)->nullable();
            $table->string('referencia_motivo', 255)->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index(['serie', 'numero']);
            $table->index('fecha_emision');
        });

        Schema::create('venta_detalle', function (Blueprint $table) {
            $table->bigIncrements('comprobante_venta_detalle_id');
            $table->unsignedBigInteger('comprobante_venta_id');
            $table->string('descripcion', 120);
            $table->string('unidad', 10)->default('UND');
            $table->decimal('cantidad', 12, 2);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('total_linea', 12, 2);

            $table->foreign('comprobante_venta_id', 'fk_cvdet_comprobante')
                ->references('comprobante_venta_id')
                ->on('ventas')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_detalle');
        Schema::dropIfExists('ventas');
    }
};
