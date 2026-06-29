<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sunat_comunicaciones_baja', function (Blueprint $table) {
            $table->id('comunicacion_baja_id');
            $table->unsignedBigInteger('comprobante_venta_id');
            $table->date('fecha_documento');
            $table->date('fecha_comunicacion');
            $table->unsignedInteger('correlativo');
            $table->string('motivo', 250);
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

            $table->foreign('comprobante_venta_id', 'fk_baja_venta')
                ->references('comprobante_venta_id')->on('ventas')->cascadeOnDelete();
            $table->index(['comprobante_venta_id', 'estado'], 'baja_venta_estado_idx');
        });

        DB::table('comprobante_series')->insertOrIgnore([
            'codigo_tipo_comprobante' => 'RA',
            'nombre_tipo' => 'Comunicacion de baja',
            'serie' => 'RA',
            'correlativo_actual' => 0,
            'activo' => true,
            'creado_en' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_comunicaciones_baja');
        DB::table('comprobante_series')->where('codigo_tipo_comprobante', 'RA')->delete();
    }
};
