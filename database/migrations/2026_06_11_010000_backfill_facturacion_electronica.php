<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ventas') || ! Schema::hasTable('venta_detalle')) {
            return;
        }

        DB::table('ventas')->whereNull('codigo_tipo_comprobante')->update([
            'codigo_tipo_comprobante' => DB::raw("CASE
                WHEN LOWER(tipo_comprobante) = 'factura' THEN '01'
                WHEN LOWER(tipo_comprobante) = 'boleta' THEN '03'
                WHEN LOWER(tipo_comprobante) = 'nota-credito' THEN '07'
                WHEN LOWER(tipo_comprobante) = 'nota-venta' THEN 'NV'
                ELSE 'NV' END"),
        ]);

        DB::table('ventas')->update([
            'operacion_exonerada' => DB::raw('subtotal'),
            'operacion_gravada' => 0,
            'operacion_inafecta' => 0,
            'igv' => 0,
            'total_impuestos' => 0,
        ]);

        DB::table('venta_detalle')->update([
            'codigo_unidad' => DB::raw("CASE WHEN UPPER(unidad) IN ('KG', 'KGM') THEN 'KGM' ELSE 'NIU' END"),
            'tipo_afectacion_igv' => '20',
            'valor_unitario' => DB::raw('precio_unitario'),
            'valor_venta' => DB::raw('total_linea'),
            'igv' => 0,
            'total_impuestos' => 0,
        ]);

        foreach (DB::table('comprobante_series')->get() as $serie) {
            $maximo = DB::table('ventas')
                ->where('serie', $serie->serie)
                ->selectRaw('MAX(CAST(numero AS UNSIGNED)) AS maximo')
                ->value('maximo');

            DB::table('comprobante_series')
                ->where('serie_id', $serie->serie_id)
                ->update(['correlativo_actual' => max((int) $serie->correlativo_actual, (int) $maximo)]);
        }
    }

    public function down(): void
    {
        // La normalización histórica no se revierte para no perder consistencia tributaria.
    }
};
