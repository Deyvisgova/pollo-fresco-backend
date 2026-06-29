<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_lote', function (Blueprint $table) {
            $table->unsignedInteger('proveedor_id')->nullable()->after('usuario_id');
            $table
                ->foreign('proveedor_id', 'fk_clote_proveedor')
                ->references('proveedor_id')
                ->on('proveedores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compras_lote', function (Blueprint $table) {
            $table->dropForeign('fk_clote_proveedor');
            $table->dropColumn('proveedor_id');
        });
    }
};
