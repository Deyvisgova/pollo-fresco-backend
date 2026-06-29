<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pagos_proveedor') || Schema::hasColumn('pagos_proveedor', 'proveedor_pagado')) {
            return;
        }

        Schema::table('pagos_proveedor', function (Blueprint $table) {
            $table->string('proveedor_pagado')->nullable()->after('cantidad_entregas');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pagos_proveedor') || !Schema::hasColumn('pagos_proveedor', 'proveedor_pagado')) {
            return;
        }

        Schema::table('pagos_proveedor', function (Blueprint $table) {
            $table->dropColumn('proveedor_pagado');
        });
    }
};
