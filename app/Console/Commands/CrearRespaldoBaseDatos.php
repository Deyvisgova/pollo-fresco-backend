<?php

namespace App\Console\Commands;

use App\Services\Mantenimiento\RespaldoBaseDatosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CrearRespaldoBaseDatos extends Command
{
    protected $signature = 'mantenimiento:respaldo {tipo=diario : diario, semanal o mensual}';
    protected $description = 'Crea un respaldo cifrado de la base de datos y aplica la retencion configurada';

    public function handle(RespaldoBaseDatosService $respaldos): int
    {
        $tipo = (string) $this->argument('tipo');

        try {
            $respaldo = $respaldos->crear($tipo);
            DB::table('mantenimiento_auditorias')->insert([
                'accion' => 'respaldo_automatico',
                'archivo' => $respaldo['archivo'],
                'estado' => 'completado',
                'detalle' => 'Tipo: '.$tipo,
                'creado_en' => now(),
            ]);
            $this->info('Respaldo creado: '.$respaldo['archivo']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
