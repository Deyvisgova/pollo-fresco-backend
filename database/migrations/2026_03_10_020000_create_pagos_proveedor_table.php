<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pagos_proveedor')) {
            return;
        }

        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->bigIncrements('pago_id');
            $table->unsignedBigInteger('usuario_id');
            $table->decimal('total', 12, 2);
            $table->decimal('monto_transferencia', 12, 2)->default(0);
            $table->decimal('monto_efectivo', 12, 2)->default(0);
            $table->decimal('saldo', 12, 2)->default(0);
            $table->string('estado', 20)->default('PENDIENTE');
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->unsignedInteger('cantidad_entregas')->default(0);
            $table->timestamp('creado_en')->useCurrent();

            $table->index(['estado', 'creado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_proveedor');
    }
};
