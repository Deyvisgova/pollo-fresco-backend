<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuracion_sunat')) {
            Schema::create('configuracion_sunat', function (Blueprint $table) {
                $table->id('configuracion_sunat_id');
                $table->string('ambiente', 20)->default('beta');
                $table->string('ruc', 11);
                $table->string('razon_social', 200);
                $table->string('nombre_comercial', 200)->nullable();
                $table->string('direccion_fiscal', 250);
                $table->string('ubigeo', 6)->nullable();
                $table->string('departamento', 100)->nullable();
                $table->string('provincia', 100)->nullable();
                $table->string('distrito', 100)->nullable();
                $table->string('correo', 150)->nullable();
                $table->string('usuario_sol', 100)->nullable();
                $table->text('clave_sol_encriptada')->nullable();
                $table->string('certificado_ruta', 255)->nullable();
                $table->text('certificado_clave_encriptada')->nullable();
                $table->boolean('activo')->default(false);
                $table->integer('actualizado_por')->nullable();
                $table->timestamp('creado_en')->useCurrent();
                $table->timestamp('actualizado_en')->nullable();
            });
        }

        if (! Schema::hasTable('comprobante_series')) {
            Schema::create('comprobante_series', function (Blueprint $table) {
                $table->id('serie_id');
                $table->string('codigo_tipo_comprobante', 4);
                $table->string('nombre_tipo', 40);
                $table->string('serie', 10);
                $table->unsignedBigInteger('correlativo_actual')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamp('creado_en')->useCurrent();
                $table->timestamp('actualizado_en')->nullable();

                $table->unique(['codigo_tipo_comprobante', 'serie'], 'series_tipo_serie_unique');
            });

            foreach ([
                ['01', 'Factura', 'F001'],
                ['03', 'Boleta', 'B001'],
                ['07', 'Nota de credito', 'FC01'],
                ['08', 'Nota de debito', 'FD01'],
                ['NV', 'Nota de venta interna', 'NV01'],
            ] as [$codigo, $nombre, $serie]) {
                DB::table('comprobante_series')->insert([
                    'codigo_tipo_comprobante' => $codigo,
                    'nombre_tipo' => $nombre,
                    'serie' => $serie,
                    'correlativo_actual' => 0,
                    'activo' => true,
                    'creado_en' => now(),
                ]);
            }
        }

        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                if (! Schema::hasColumn('ventas', 'codigo_tipo_comprobante')) {
                    $table->string('codigo_tipo_comprobante', 4)->nullable()->after('tipo_comprobante');
                }
                if (! Schema::hasColumn('ventas', 'estado_sunat')) {
                    $table->string('estado_sunat', 30)->default('NO_ENVIADO')->after('numero');
                }
                if (! Schema::hasColumn('ventas', 'respuesta_sunat_codigo')) {
                    $table->string('respuesta_sunat_codigo', 20)->nullable()->after('estado_sunat');
                }
                if (! Schema::hasColumn('ventas', 'respuesta_sunat_descripcion')) {
                    $table->text('respuesta_sunat_descripcion')->nullable()->after('respuesta_sunat_codigo');
                }
                if (! Schema::hasColumn('ventas', 'xml_firmado_ruta')) {
                    $table->string('xml_firmado_ruta', 255)->nullable()->after('respuesta_sunat_descripcion');
                }
                if (! Schema::hasColumn('ventas', 'cdr_ruta')) {
                    $table->string('cdr_ruta', 255)->nullable()->after('xml_firmado_ruta');
                }
                if (! Schema::hasColumn('ventas', 'codigo_hash')) {
                    $table->string('codigo_hash', 120)->nullable()->after('cdr_ruta');
                }
                if (! Schema::hasColumn('ventas', 'enviado_sunat_en')) {
                    $table->dateTime('enviado_sunat_en')->nullable()->after('codigo_hash');
                }
                if (! Schema::hasColumn('ventas', 'operacion_gravada')) {
                    $table->decimal('operacion_gravada', 14, 2)->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('ventas', 'operacion_exonerada')) {
                    $table->decimal('operacion_exonerada', 14, 2)->default(0)->after('operacion_gravada');
                }
                if (! Schema::hasColumn('ventas', 'operacion_inafecta')) {
                    $table->decimal('operacion_inafecta', 14, 2)->default(0)->after('operacion_exonerada');
                }
                if (! Schema::hasColumn('ventas', 'igv')) {
                    $table->decimal('igv', 14, 2)->default(0)->after('operacion_inafecta');
                }
                if (! Schema::hasColumn('ventas', 'total_impuestos')) {
                    $table->decimal('total_impuestos', 14, 2)->default(0)->after('igv');
                }
            });
        }

        if (Schema::hasTable('venta_detalle')) {
            Schema::table('venta_detalle', function (Blueprint $table) {
                if (! Schema::hasColumn('venta_detalle', 'codigo_unidad')) {
                    $table->string('codigo_unidad', 5)->default('NIU')->after('unidad');
                }
                if (! Schema::hasColumn('venta_detalle', 'tipo_afectacion_igv')) {
                    $table->string('tipo_afectacion_igv', 4)->default('20')->after('codigo_unidad');
                }
                if (! Schema::hasColumn('venta_detalle', 'valor_unitario')) {
                    $table->decimal('valor_unitario', 14, 6)->default(0)->after('precio_unitario');
                }
                if (! Schema::hasColumn('venta_detalle', 'valor_venta')) {
                    $table->decimal('valor_venta', 14, 2)->default(0)->after('valor_unitario');
                }
                if (! Schema::hasColumn('venta_detalle', 'igv')) {
                    $table->decimal('igv', 14, 2)->default(0)->after('valor_venta');
                }
                if (! Schema::hasColumn('venta_detalle', 'total_impuestos')) {
                    $table->decimal('total_impuestos', 14, 2)->default(0)->after('igv');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_series');
        Schema::dropIfExists('configuracion_sunat');
    }
};
