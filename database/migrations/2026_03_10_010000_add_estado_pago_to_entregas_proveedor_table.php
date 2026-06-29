<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('entregas_proveedor', function (Blueprint $table) {
            if (!Schema::hasColumn('entregas_proveedor', 'estado_pago')) {
                $table->string('estado_pago', 20)->default('PENDIENTE')->after('tipo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('entregas_proveedor', function (Blueprint $table) {
            if (Schema::hasColumn('entregas_proveedor', 'estado_pago')) {
                $table->dropColumn('estado_pago');
            }
        });
    }
};
