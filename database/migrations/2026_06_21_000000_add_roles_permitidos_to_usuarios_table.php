<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('usuarios', 'roles_permitidos')) {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->text('roles_permitidos')->nullable()->after('rol_id');
            });
        }

        DB::table('usuarios')
            ->whereNull('roles_permitidos')
            ->orWhere('roles_permitidos', '')
            ->orderBy('usuario_id')
            ->chunkById(100, function ($usuarios) {
                foreach ($usuarios as $usuario) {
                    DB::table('usuarios')
                        ->where('usuario_id', $usuario->usuario_id)
                        ->update([
                            'roles_permitidos' => json_encode([(int) $usuario->rol_id]),
                        ]);
                }
            }, 'usuario_id');
    }

    public function down(): void
    {
        if (Schema::hasColumn('usuarios', 'roles_permitidos')) {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->dropColumn('roles_permitidos');
            });
        }
    }
};
