<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pedidos')
            ->where('estado_id', 4)
            ->update(['estado_id' => 1]);

        DB::table('pedido_estados')
            ->where('estado_id', 4)
            ->where('nombre', 'EN_RUTA')
            ->delete();
    }

    public function down(): void
    {
        DB::table('pedido_estados')->updateOrInsert(
            ['estado_id' => 4],
            ['nombre' => 'EN_RUTA']
        );
    }
};
