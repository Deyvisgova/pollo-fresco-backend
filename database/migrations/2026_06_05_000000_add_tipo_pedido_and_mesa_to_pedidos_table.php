<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            if (!Schema::hasColumn('pedidos', 'tipo_pedido')) {
                $table->string('tipo_pedido', 20)->default('DELIVERY')->after('estado_id');
            }

            if (!Schema::hasColumn('pedidos', 'mesa')) {
                $table->string('mesa', 50)->nullable()->after('tipo_pedido');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            if (Schema::hasColumn('pedidos', 'mesa')) {
                $table->dropColumn('mesa');
            }

            if (Schema::hasColumn('pedidos', 'tipo_pedido')) {
                $table->dropColumn('tipo_pedido');
            }
        });
    }
};
