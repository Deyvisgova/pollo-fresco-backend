<?php

use Illuminate\Database\Migrations\Migration;

use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (! Schema::hasTable('ventas') && Schema::hasTable('comprobantes_venta')) {
            Schema::rename('comprobantes_venta', 'ventas');
        }

        if (! Schema::hasTable('venta_detalle') && Schema::hasTable('comprobantes_venta_detalle')) {
            Schema::rename('comprobantes_venta_detalle', 'venta_detalle');
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('venta_detalle')) {
            Schema::rename('venta_detalle', 'comprobantes_venta_detalle');
        }

        if (Schema::hasTable('ventas')) {
            Schema::rename('ventas', 'comprobantes_venta');

        }

        Schema::enableForeignKeyConstraints();
    }
};
