<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'pedido_id')) {
                $table->unsignedBigInteger('pedido_id')->nullable()->after('usuario_id');
                $table->unique('pedido_id', 'ventas_pedido_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            if (Schema::hasColumn('ventas', 'pedido_id')) {
                $table->dropUnique('ventas_pedido_unique');
                $table->dropColumn('pedido_id');
            }
        });
    }
};
