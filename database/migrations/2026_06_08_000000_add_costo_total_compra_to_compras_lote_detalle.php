<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('compras_lote_detalle')) {
            return;
        }

        Schema::table('compras_lote_detalle', function (Blueprint $table) {
            if (!Schema::hasColumn('compras_lote_detalle', 'costo_total_compra')) {
                $table->decimal('costo_total_compra', 12, 2)->nullable()->after('costo_kilo');
            }
        });

        DB::table('compras_lote_detalle')
            ->whereNull('costo_total_compra')
            ->update([
                'costo_total_compra' => DB::raw("
                    ROUND(
                        CASE
                            WHEN cantidad_presentacion IS NOT NULL
                                AND factor_conversion IS NOT NULL
                                AND cantidad_presentacion > 0
                                AND factor_conversion > 0
                            THEN cantidad_presentacion * factor_conversion * costo_kilo
                            ELSE cantidad * costo_kilo
                        END,
                        2
                    )
                "),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('compras_lote_detalle')) {
            return;
        }

        Schema::table('compras_lote_detalle', function (Blueprint $table) {
            if (Schema::hasColumn('compras_lote_detalle', 'costo_total_compra')) {
                $table->dropColumn('costo_total_compra');
            }
        });
    }
};
