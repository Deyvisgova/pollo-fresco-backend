<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compras_lote_detalle')) {
            Schema::table('compras_lote_detalle', function (Blueprint $table) {
                if (!Schema::hasColumn('compras_lote_detalle', 'presentacion_ingreso')) {
                    $table->string('presentacion_ingreso', 30)->nullable()->after('cantidad');
                }
                if (!Schema::hasColumn('compras_lote_detalle', 'cantidad_presentacion')) {
                    $table->decimal('cantidad_presentacion', 12, 2)->nullable()->after('presentacion_ingreso');
                }
                if (!Schema::hasColumn('compras_lote_detalle', 'factor_conversion')) {
                    $table->decimal('factor_conversion', 12, 2)->nullable()->after('cantidad_presentacion');
                }
            });
        }

        if (Schema::hasTable('otros_productos_ventas_diarias')) {
            Schema::table('otros_productos_ventas_diarias', function (Blueprint $table) {
                if (!Schema::hasColumn('otros_productos_ventas_diarias', 'presentacion_venta')) {
                    $table->string('presentacion_venta', 30)->nullable()->after('cantidad');
                }
                if (!Schema::hasColumn('otros_productos_ventas_diarias', 'cantidad_presentacion')) {
                    $table->decimal('cantidad_presentacion', 12, 2)->nullable()->after('presentacion_venta');
                }
                if (!Schema::hasColumn('otros_productos_ventas_diarias', 'factor_conversion')) {
                    $table->decimal('factor_conversion', 12, 2)->nullable()->after('cantidad_presentacion');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('otros_productos_ventas_diarias')) {
            Schema::table('otros_productos_ventas_diarias', function (Blueprint $table) {
                foreach (['factor_conversion', 'cantidad_presentacion', 'presentacion_venta'] as $column) {
                    if (Schema::hasColumn('otros_productos_ventas_diarias', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('compras_lote_detalle')) {
            Schema::table('compras_lote_detalle', function (Blueprint $table) {
                foreach (['factor_conversion', 'cantidad_presentacion', 'presentacion_ingreso'] as $column) {
                    if (Schema::hasColumn('compras_lote_detalle', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
