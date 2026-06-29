<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sunat_resumenes_diarios', function (Blueprint $table) {
            $table->id('resumen_id');
            $table->date('fecha_documentos');
            $table->date('fecha_resumen');
            $table->unsignedInteger('correlativo');
            $table->string('nombre_archivo', 100);
            $table->string('ticket', 100)->nullable();
            $table->string('estado', 35)->default('PENDIENTE');
            $table->string('respuesta_codigo', 20)->nullable();
            $table->text('respuesta_descripcion')->nullable();
            $table->string('xml_ruta', 255)->nullable();
            $table->string('cdr_ruta', 255)->nullable();
            $table->unsignedInteger('usuario_id')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->timestamp('consultado_en')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['fecha_resumen', 'correlativo'], 'resumen_fecha_correlativo_unique');
            $table->index(['fecha_documentos', 'estado'], 'resumen_fecha_estado_idx');
        });

        Schema::create('sunat_resumen_detalles', function (Blueprint $table) {
            $table->id('resumen_detalle_id');
            $table->unsignedBigInteger('resumen_id');
            $table->unsignedBigInteger('comprobante_venta_id');
            $table->string('estado_item', 2)->default('1');
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['resumen_id', 'comprobante_venta_id'], 'resumen_venta_unique');
            $table->foreign('resumen_id', 'fk_resumen_detalle_resumen')
                ->references('resumen_id')->on('sunat_resumenes_diarios')->cascadeOnDelete();
            $table->foreign('comprobante_venta_id', 'fk_resumen_detalle_venta')
                ->references('comprobante_venta_id')->on('ventas')->cascadeOnDelete();
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->unsignedBigInteger('venta_referencia_id')->nullable()->after('referencia_motivo');
            $table->string('nota_motivo_codigo', 4)->nullable()->after('venta_referencia_id');
        });

        DB::table('comprobante_series')->insertOrIgnore([
            'codigo_tipo_comprobante' => 'RC',
            'nombre_tipo' => 'Resumen diario de boletas',
            'serie' => 'RC',
            'correlativo_actual' => 0,
            'activo' => true,
            'creado_en' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['venta_referencia_id', 'nota_motivo_codigo']);
        });
        Schema::dropIfExists('sunat_resumen_detalles');
        Schema::dropIfExists('sunat_resumenes_diarios');
        DB::table('comprobante_series')->where('codigo_tipo_comprobante', 'RC')->delete();
    }
};
