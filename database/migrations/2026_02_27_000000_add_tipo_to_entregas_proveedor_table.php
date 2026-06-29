<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entregas_proveedor', function (Blueprint $table) {
            if (!Schema::hasColumn('entregas_proveedor', 'tipo')) {
                $table->string('tipo', 50)->default('POLLO')->after('costo_total');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entregas_proveedor', function (Blueprint $table) {
            if (Schema::hasColumn('entregas_proveedor', 'tipo')) {
                $table->dropColumn('tipo');
            }
        });
    }
};
