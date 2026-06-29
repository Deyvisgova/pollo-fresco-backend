<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gastos')) {
            Schema::table('gastos', function (Blueprint $table) {
                if (!Schema::hasColumn('gastos', 'fondo')) {
                    $table->string('fondo', 30)->default('POLLO_GALLINA')->after('categoria_id');
                }

                if (!Schema::hasColumn('gastos', 'nota')) {
                    $table->string('nota', 250)->nullable()->after('monto');
                }
            });
        }

        if (!Schema::hasTable('ventas_pollo_gallina_diarias')) {
            Schema::create('ventas_pollo_gallina_diarias', function (Blueprint $table) {
                $table->increments('venta_pg_id');
                $table->integer('usuario_id');
                $table->date('fecha');
                $table->decimal('venta_pollo', 10, 2)->default(0);
                $table->decimal('venta_gallina', 10, 2)->default(0);
                $table->string('observacion', 250)->nullable();
                $table->timestamp('creado_en')->useCurrent();
                $table->timestamp('actualizado_en')->nullable();

                $table->unique(['usuario_id', 'fecha'], 'uq_ventas_pg_usuario_fecha');
                $table->foreign('usuario_id', 'fk_ventas_pg_usuario')
                    ->references('usuario_id')
                    ->on('usuarios')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('ventas_pollo_gallina_diarias')) {
            DB::statement('ALTER TABLE ventas_pollo_gallina_diarias MODIFY usuario_id INT(11) NOT NULL');

            $tieneFk = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'ventas_pollo_gallina_diarias')
                ->where('CONSTRAINT_NAME', 'fk_ventas_pg_usuario')
                ->exists();

            if (!$tieneFk) {
                Schema::table('ventas_pollo_gallina_diarias', function (Blueprint $table) {
                    $table->foreign('usuario_id', 'fk_ventas_pg_usuario')
                        ->references('usuario_id')
                        ->on('usuarios')
                        ->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas_pollo_gallina_diarias')) {
            Schema::drop('ventas_pollo_gallina_diarias');
        }

        if (Schema::hasTable('gastos')) {
            Schema::table('gastos', function (Blueprint $table) {
                if (Schema::hasColumn('gastos', 'nota')) {
                    $table->dropColumn('nota');
                }

                if (Schema::hasColumn('gastos', 'fondo')) {
                    $table->dropColumn('fondo');
                }
            });
        }
    }
};
