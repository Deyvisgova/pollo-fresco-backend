<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('otros_productos_ventas_diarias')) {
            return;
        }

        Schema::table('otros_productos_ventas_diarias', function (Blueprint $table) {
            if (!Schema::hasColumn('otros_productos_ventas_diarias', 'pedido_id')) {
                $table->unsignedBigInteger('pedido_id')->nullable()->after('usuario_id');
            }

            if (!Schema::hasColumn('otros_productos_ventas_diarias', 'origen')) {
                $table->string('origen', 20)->nullable()->after('pedido_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('otros_productos_ventas_diarias')) {
            return;
        }

        Schema::table('otros_productos_ventas_diarias', function (Blueprint $table) {
            if (Schema::hasColumn('otros_productos_ventas_diarias', 'origen')) {
                $table->dropColumn('origen');
            }

            if (Schema::hasColumn('otros_productos_ventas_diarias', 'pedido_id')) {
                $table->dropColumn('pedido_id');
            }
        });
    }
};
