<?php

use Illuminate\Database\Migrations\Migration;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('venta_detalle')) {
            Schema::drop('venta_detalle');
        }

        if (Schema::hasTable('ventas')) {
            Schema::drop('ventas');
        }

        if (Schema::hasTable('comprobantes_venta')) {
            Schema::rename('comprobantes_venta', 'ventas');
        }

        if (Schema::hasTable('comprobantes_venta_detalle')) {
            Schema::rename('comprobantes_venta_detalle', 'venta_detalle');
        }


        if (Schema::hasTable('ventas')) {
            DB::statement('ALTER TABLE ventas MODIFY usuario_id INT UNSIGNED NOT NULL');
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


            DB::statement('ALTER TABLE comprobantes_venta MODIFY usuario_id BIGINT UNSIGNED NOT NULL');

        }

        Schema::enableForeignKeyConstraints();
    }
};
