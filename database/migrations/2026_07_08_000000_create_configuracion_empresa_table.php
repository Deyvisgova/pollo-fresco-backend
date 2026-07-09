<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuracion_empresa')) {
            Schema::create('configuracion_empresa', function (Blueprint $table) {
                $table->id('configuracion_empresa_id');
                $table->string('nombre_empresa', 180)->default('Nombre de la empresa');
                $table->string('logo_url', 500)->nullable();
                $table->timestamp('creado_en')->useCurrent();
                $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! DB::table('configuracion_empresa')->exists()) {
            DB::table('configuracion_empresa')->insert([
                'nombre_empresa' => 'POLLO FRESCO',
                'logo_url' => null,
                'creado_en' => now(),
                'actualizado_en' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_empresa');
    }
};
