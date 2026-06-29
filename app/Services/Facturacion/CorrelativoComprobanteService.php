<?php

namespace App\Services\Facturacion;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrelativoComprobanteService
{
    private const CODIGOS = [
        'factura' => '01',
        'boleta' => '03',
        'nota-credito' => '07',
        'nota-debito' => '08',
        'nota-venta' => 'NV',
    ];

    public function codigoTipo(string $tipo): string
    {
        $codigo = self::CODIGOS[strtolower(trim($tipo))] ?? null;

        if (! $codigo) {
            throw ValidationException::withMessages([
                'tipo_comprobante' => 'El tipo de comprobante no está configurado.',
            ]);
        }

        return $codigo;
    }

    public function vistaPrevia(string $tipo): array
    {
        $registro = $this->buscarSerie($this->codigoTipo($tipo), false);

        return $this->formatear($registro, ((int) $registro->correlativo_actual) + 1);
    }

    public function reservar(string $tipo): array
    {
        $registro = $this->buscarSerie($this->codigoTipo($tipo), true);
        $correlativo = ((int) $registro->correlativo_actual) + 1;

        DB::table('comprobante_series')
            ->where('serie_id', $registro->serie_id)
            ->update([
                'correlativo_actual' => $correlativo,
                'actualizado_en' => now(),
            ]);

        return $this->formatear($registro, $correlativo);
    }

    private function buscarSerie(string $codigo, bool $bloquear): object
    {
        $consulta = DB::table('comprobante_series')
            ->where('codigo_tipo_comprobante', $codigo)
            ->where('activo', true)
            ->orderBy('serie_id');

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $registro = $consulta->first();

        if (! $registro) {
            throw ValidationException::withMessages([
                'tipo_comprobante' => 'No existe una serie activa para este tipo de comprobante.',
            ]);
        }

        return $registro;
    }

    private function formatear(object $registro, int $correlativo): array
    {
        return [
            'codigo_tipo_comprobante' => $registro->codigo_tipo_comprobante,
            'serie' => $registro->serie,
            'numero' => str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT),
            'correlativo' => $correlativo,
        ];
    }
}
