<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gastos')) {
            Schema::table('gastos', function (Blueprint $table) {
                if (!Schema::hasColumn('gastos', 'estado')) {
                    $table->string('estado', 20)->default('ACTIVO')->after('nota');
                }

                if (!Schema::hasColumn('gastos', 'anulado_en')) {
                    $table->dateTime('anulado_en')->nullable()->after('estado');
                }

                if (!Schema::hasColumn('gastos', 'anulado_por')) {
                    $table->integer('anulado_por')->nullable()->after('anulado_en');
                }

                if (!Schema::hasColumn('gastos', 'motivo_anulacion')) {
                    $table->string('motivo_anulacion', 250)->nullable()->after('anulado_por');
                }
            });
        }

        if (!Schema::hasTable('gasto_auditoria')) {
            Schema::create('gasto_auditoria', function (Blueprint $table) {
                $table->increments('auditoria_id');
                $table->integer('usuario_id')->nullable();
                $table->string('accion', 40);
                $table->string('entidad', 40);
                $table->integer('entidad_id')->nullable();
                $table->string('fondo', 30)->nullable();
                $table->string('descripcion', 250);
                $table->json('datos_antes')->nullable();
                $table->json('datos_despues')->nullable();
                $table->dateTime('creado_en')->useCurrent();

                $table->index(['entidad', 'entidad_id'], 'idx_auditoria_entidad');
                $table->index(['fondo', 'creado_en'], 'idx_auditoria_fondo_fecha');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gasto_auditoria')) {
            Schema::drop('gasto_auditoria');
        }

        if (Schema::hasTable('gastos')) {
            Schema::table('gastos', function (Blueprint $table) {
                foreach (['motivo_anulacion', 'anulado_por', 'anulado_en', 'estado'] as $column) {
                    if (Schema::hasColumn('gastos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
