<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComprobantePdfController extends Controller
{
    public function descargar(Request $request, int $ventaId)
    {
        abort_if($request->user()?->role === 'delivery', 403, 'El rol delivery no puede gestionar facturacion.');
        $formato = (string) $request->query('formato', 'a4');
        abort_unless(in_array($formato, ['a4', 'ticket-80', 'ticket-57'], true), 422, 'Formato invalido.');

        $venta = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();
        abort_unless($venta, 404, 'Venta no encontrada.');
        $detalles = DB::table('venta_detalle')->where('comprobante_venta_id', $ventaId)
            ->orderBy('comprobante_venta_detalle_id')->get();
        $emisor = DB::table('configuracion_sunat')->orderByDesc('configuracion_sunat_id')->first();

        $qrTexto = implode('|', [
            $emisor?->ruc ?: '',
            $venta->codigo_tipo_comprobante ?: 'NV',
            $venta->serie,
            $venta->numero,
            number_format((float) ($venta->igv ?? 0), 2, '.', ''),
            number_format((float) $venta->total, 2, '.', ''),
            $venta->fecha_emision,
            $venta->cliente_tipo_documento === 'ruc' ? '6' : ($venta->cliente_documento ? '1' : '0'),
            $venta->cliente_documento ?: '',
            $venta->codigo_hash ?: '',
        ]);
        $qrSvg = (new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd())))
            ->writeString($qrTexto);
        $papel = match ($formato) {
            'ticket-80' => [0, 0, 226.77, 700],
            'ticket-57' => [0, 0, 161.57, 700],
            default => 'a4',
        };

        return Pdf::loadView('pdf.comprobante-venta', compact('venta', 'detalles', 'emisor', 'qrSvg', 'formato'))
            ->setPaper($papel)
            ->download("comprobante-{$venta->serie}-{$venta->numero}-{$formato}.pdf");
    }
}
