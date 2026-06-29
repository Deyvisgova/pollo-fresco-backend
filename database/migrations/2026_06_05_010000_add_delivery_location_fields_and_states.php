<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasColumn('clientes', 'latitud')) {
                $table->decimal('latitud', 10, 7)->nullable()->after('referencias');
            }

            if (!Schema::hasColumn('clientes', 'longitud')) {
                $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
            }

            if (!Schema::hasColumn('clientes', 'foto_frontis_url')) {
                $table->string('foto_frontis_url', 255)->nullable()->after('longitud');
            }

            if (!Schema::hasColumn('clientes', 'ubicacion_actualizada_por')) {
                $table->unsignedInteger('ubicacion_actualizada_por')->nullable()->after('foto_frontis_url');
            }

            if (!Schema::hasColumn('clientes', 'ubicacion_actualizada_en')) {
                $table->dateTime('ubicacion_actualizada_en')->nullable()->after('ubicacion_actualizada_por');
            }
        });

        DB::table('pedido_estados')->updateOrInsert(['estado_id' => 4], ['nombre' => 'EN_RUTA']);
        DB::table('pedido_estados')->updateOrInsert(['estado_id' => 5], ['nombre' => 'NO_ENTREGADO']);
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            foreach (['ubicacion_actualizada_en', 'ubicacion_actualizada_por', 'foto_frontis_url', 'longitud', 'latitud'] as $columna) {
                if (Schema::hasColumn('clientes', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        DB::table('pedido_estados')->whereIn('estado_id', [4, 5])->delete();
    }
};
