<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\Facturacion\CorrelativoComprobanteService;
use App\Services\Facturacion\FacturacionElectronicaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VentaController extends Controller
{
    public function __construct(
        private readonly CorrelativoComprobanteService $correlativos,
        private readonly FacturacionElectronicaService $facturacionElectronica
    ) {
    }

    public function siguienteCorrelativo(Request $request)
    {
        $this->autorizarFacturacion($request);
        $request->validate([
            'tipo' => ['required', 'string', 'max:20'],
        ]);

        return response()->json($this->correlativos->vistaPrevia((string) $request->query('tipo')));
    }

    public function enviarSunat(int $ventaId)
    {
        $this->autorizarFacturacion(request());
        try {
            return response()->json($this->facturacionElectronica->enviar($ventaId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function crearNotaCredito(Request $request, int $ventaId)
    {
        $this->autorizarFacturacion($request);
        $datos = $request->validate([
            'motivo_codigo' => ['required', 'in:01,06'],
            'motivo_descripcion' => ['required', 'string', 'max:250'],
        ]);

        $referencia = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();
        if (! $referencia || $referencia->codigo_tipo_comprobante !== '01') {
            return response()->json(['message' => 'La nota de credito debe referenciar una factura.'], 422);
        }
        if (! in_array($referencia->estado_sunat, ['ACEPTADO', 'ACEPTADO_CON_OBSERVACIONES'], true)) {
            return response()->json(['message' => 'Solo se puede emitir nota de credito para una factura aceptada por SUNAT.'], 422);
        }
        $existente = DB::table('ventas')
            ->where('venta_referencia_id', $ventaId)
            ->where('codigo_tipo_comprobante', '07')
            ->whereNotIn('estado_sunat', ['RECHAZADO'])
            ->exists();
        if ($existente) {
            return response()->json(['message' => 'Esta factura ya tiene una nota de credito vigente.'], 422);
        }

        try {
            $notaId = DB::transaction(function () use ($request, $referencia, $ventaId, $datos) {
                $correlativo = $this->correlativos->reservar('nota-credito');
                $notaId = DB::table('ventas')->insertGetId([
                    'usuario_id' => $request->user()->usuario_id,
                    'tipo_comprobante' => 'nota-credito',
                    'codigo_tipo_comprobante' => '07',
                    'serie' => $correlativo['serie'],
                    'numero' => $correlativo['numero'],
                    'estado_sunat' => 'NO_ENVIADO',
                    'fecha_emision' => now()->toDateString(),
                    'moneda' => $referencia->moneda,
                    'forma_pago' => 'Contado',
                    'metodo_pago' => $referencia->metodo_pago,
                    'cliente_tipo_documento' => $referencia->cliente_tipo_documento,
                    'cliente_documento' => $referencia->cliente_documento,
                    'cliente_nombre' => $referencia->cliente_nombre,
                    'cliente_direccion' => $referencia->cliente_direccion,
                    'subtotal' => $referencia->subtotal,
                    'operacion_gravada' => $referencia->operacion_gravada,
                    'operacion_exonerada' => $referencia->operacion_exonerada,
                    'operacion_inafecta' => $referencia->operacion_inafecta,
                    'igv' => $referencia->igv,
                    'total_impuestos' => $referencia->total_impuestos,
                    'total' => $referencia->total,
                    'vuelto' => 0,
                    'referencia_serie' => $referencia->serie,
                    'referencia_numero' => $referencia->numero,
                    'referencia_motivo' => $datos['motivo_descripcion'],
                    'venta_referencia_id' => $ventaId,
                    'nota_motivo_codigo' => $datos['motivo_codigo'],
                    'creado_en' => now(),
                ]);

                $detalles = DB::table('venta_detalle')->where('comprobante_venta_id', $ventaId)->get();
                foreach ($detalles as $detalle) {
                    $copia = (array) $detalle;
                    unset($copia['comprobante_venta_detalle_id']);
                    $copia['comprobante_venta_id'] = $notaId;
                    DB::table('venta_detalle')->insert($copia);
                }

                return $notaId;
            });

            return response()->json(DB::table('ventas')->where('comprobante_venta_id', $notaId)->first(), 201);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo crear la nota de credito.'], 500);
        }
    }

    public function crearNotaDebito(Request $request, int $ventaId)
    {
        $this->autorizarFacturacion($request);
        $datos = $request->validate([
            'motivo_codigo' => ['required', 'in:01,02,03'],
            'motivo_descripcion' => ['required', 'string', 'max:250'],
            'concepto' => ['required', 'string', 'max:120'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
        ]);

        $referencia = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();
        if (! $referencia || $referencia->codigo_tipo_comprobante !== '01') {
            return response()->json(['message' => 'La nota de debito debe referenciar una factura.'], 422);
        }
        if (! in_array($referencia->estado_sunat, ['ACEPTADO', 'ACEPTADO_CON_OBSERVACIONES'], true)) {
            return response()->json(['message' => 'Solo se puede emitir nota de debito para una factura aceptada por SUNAT.'], 422);
        }
        if ((float) $referencia->operacion_gravada > 0 && (float) $referencia->operacion_exonerada > 0) {
            return response()->json([
                'message' => 'La factura tiene operaciones mixtas. Debe indicar el tratamiento tributario por concepto.',
            ], 422);
        }

        try {
            $notaId = DB::transaction(function () use ($request, $referencia, $ventaId, $datos) {
                $correlativo = $this->correlativos->reservar('nota-debito');
                $total = round((float) $datos['monto'], 2);
                $esGravada = (float) $referencia->operacion_gravada > 0;
                $valorVenta = $esGravada ? round($total / 1.18, 2) : $total;
                $igv = $esGravada ? round($total - $valorVenta, 2) : 0;

                $notaId = DB::table('ventas')->insertGetId([
                    'usuario_id' => $request->user()->usuario_id,
                    'tipo_comprobante' => 'nota-debito',
                    'codigo_tipo_comprobante' => '08',
                    'serie' => $correlativo['serie'],
                    'numero' => $correlativo['numero'],
                    'estado_sunat' => 'NO_ENVIADO',
                    'fecha_emision' => now()->toDateString(),
                    'moneda' => $referencia->moneda,
                    'forma_pago' => 'Contado',
                    'metodo_pago' => $referencia->metodo_pago,
                    'cliente_tipo_documento' => $referencia->cliente_tipo_documento,
                    'cliente_documento' => $referencia->cliente_documento,
                    'cliente_nombre' => $referencia->cliente_nombre,
                    'cliente_direccion' => $referencia->cliente_direccion,
                    'subtotal' => $valorVenta,
                    'operacion_gravada' => $esGravada ? $valorVenta : 0,
                    'operacion_exonerada' => $esGravada ? 0 : $valorVenta,
                    'operacion_inafecta' => 0,
                    'igv' => $igv,
                    'total_impuestos' => $igv,
                    'total' => $total,
                    'vuelto' => 0,
                    'referencia_serie' => $referencia->serie,
                    'referencia_numero' => $referencia->numero,
                    'referencia_motivo' => $datos['motivo_descripcion'],
                    'venta_referencia_id' => $ventaId,
                    'nota_motivo_codigo' => $datos['motivo_codigo'],
                    'creado_en' => now(),
                ]);

                DB::table('venta_detalle')->insert([
                    'comprobante_venta_id' => $notaId,
                    'descripcion' => trim($datos['concepto']),
                    'unidad' => 'UND',
                    'codigo_unidad' => 'NIU',
                    'cantidad' => 1,
                    'precio_unitario' => $total,
                    'tipo_afectacion_igv' => $esGravada ? '10' : '20',
                    'valor_unitario' => $valorVenta,
                    'valor_venta' => $valorVenta,
                    'igv' => $igv,
                    'total_impuestos' => $igv,
                    'total_linea' => $total,
                ]);

                return $notaId;
            });

            return response()->json(DB::table('ventas')->where('comprobante_venta_id', $notaId)->first(), 201);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo crear la nota de debito.'], 500);
        }
    }

    public function index(Request $request)
    {
        $this->autorizarFacturacion($request);
        $this->asegurarTablasVenta();

        $buscar = trim((string) $request->query('buscar', ''));
        $fechaDesde = trim((string) $request->query('fecha_desde', ''));
        $fechaHasta = trim((string) $request->query('fecha_hasta', ''));

        $query = DB::table('ventas');

        if ($buscar !== '') {
            $query->where(function ($builder) use ($buscar) {
                $builder
                    ->where('serie', 'like', "%{$buscar}%")
                    ->orWhere('numero', 'like', "%{$buscar}%")
                    ->orWhere('tipo_comprobante', 'like', "%{$buscar}%")
                    ->orWhere('cliente_nombre', 'like', "%{$buscar}%")
                    ->orWhere('cliente_documento', 'like', "%{$buscar}%");
            });
        }

        if ($fechaDesde !== '') {
            $query->whereDate('fecha_emision', '>=', $fechaDesde);
        }

        if ($fechaHasta !== '') {
            $query->whereDate('fecha_emision', '<=', $fechaHasta);
        }

        $ventas = $query
            ->orderByDesc('comprobante_venta_id')
            ->limit(200)
            ->get();

        return response()->json($ventas);
    }

    public function store(Request $request)
    {
        $this->autorizarFacturacion($request);
        $this->asegurarTablasVenta();

        $validator = Validator::make($request->all(), [
            'tipo_comprobante' => ['required', 'string', 'in:factura,boleta,nota-venta'],
            'fecha_emision' => ['required', 'date', 'before_or_equal:today'],
            'moneda' => ['required', 'in:PEN'],
            'forma_pago' => ['required', 'in:Contado'],
            'metodo_pago' => ['required', 'in:efectivo,tarjeta,transferencia,plin,yape,otro'],
            'pedido_id' => ['nullable', 'integer', 'exists:pedidos,pedido_id'],
            'cliente_tipo_documento' => ['nullable', 'in:ruc,dni'],
            'cliente_documento' => ['nullable', 'string', 'max:20'],
            'cliente_nombre' => ['nullable', 'string', 'max:150'],
            'cliente_direccion' => ['nullable', 'string', 'max:255'],
            'monto_recibido' => ['nullable', 'numeric', 'min:0'],
            'vuelto' => ['nullable', 'numeric', 'min:0'],
            'referencia_serie' => ['nullable', 'string', 'max:10'],
            'referencia_numero' => ['nullable', 'string', 'max:20'],
            'referencia_motivo' => ['nullable', 'string', 'max:255'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.descripcion' => ['required', 'string', 'max:120'],
            'detalles.*.unidad' => ['required', 'string', 'max:10'],
            'detalles.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'detalles.*.precio_unitario' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos para guardar la venta.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tipoComprobante = strtolower(trim((string) $request->input('tipo_comprobante')));
        $documentoCliente = preg_replace('/\D+/', '', (string) $request->input('cliente_documento')) ?? '';
        if ($tipoComprobante === 'factura' && strlen($documentoCliente) !== 11) {
            return response()->json([
                'message' => 'Una factura requiere un cliente identificado con RUC de 11 dígitos.',
            ], 422);
        }
        if ($tipoComprobante === 'factura' && trim((string) $request->input('cliente_nombre')) === '') {
            return response()->json([
                'message' => 'Una factura requiere la razon social del cliente.',
            ], 422);
        }
        if ($tipoComprobante === 'boleta' && $documentoCliente !== '' && strlen($documentoCliente) !== 8) {
            return response()->json([
                'message' => 'Para una boleta, el DNI debe tener 8 dígitos.',
            ], 422);
        }

        $usuario = $request->user();
        if (!$usuario) {
            return response()->json(['message' => 'Usuario no autenticado'], 401);
        }

        $pedidoId = $request->integer('pedido_id') ?: null;
        if ($pedidoId !== null) {
            if (!in_array($tipoComprobante, ['factura', 'boleta'], true)) {
                return response()->json([
                    'message' => 'Desde un pedido pagado solo puedes emitir boleta o factura.',
                ], 422);
            }
            $pedido = Pedido::with(['detalles', 'pagos'])->find($pedidoId);
            if (!$pedido) {
                return response()->json(['message' => 'El pedido indicado no existe.'], 404);
            }
            if ($this->saldoPedido($pedido) > 0) {
                return response()->json(['message' => 'El pedido debe estar completamente pagado antes de emitir el comprobante.'], 422);
            }
            if (DB::table('ventas')->where('pedido_id', $pedidoId)->exists()) {
                return response()->json(['message' => 'Este pedido ya tiene un comprobante emitido.'], 422);
            }
        }

        $detalles = collect($request->input('detalles', []))
            ->map(function (array $detalle) {
                $cantidad = (float) $detalle['cantidad'];
                $precio = (float) $detalle['precio_unitario'];

                return [
                    'descripcion' => trim((string) $detalle['descripcion']),
                    'unidad' => trim((string) $detalle['unidad']),
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'total_linea' => round($cantidad * $precio, 2),
                    'codigo_unidad' => $this->codigoUnidad((string) $detalle['unidad']),
                    'tipo_afectacion_igv' => '20',
                    'valor_unitario' => $precio,
                    'valor_venta' => round($cantidad * $precio, 2),
                    'igv' => 0,
                    'total_impuestos' => 0,
                ];
            })
            ->values();

        $totalCalculado = round((float) $detalles->sum('total_linea'), 2);
        if ($pedidoId !== null && abs($totalCalculado - round((float) $pedido->total, 2)) > 0.009) {
            return response()->json([
                'message' => 'El total del comprobante debe coincidir con el total del pedido.',
            ], 422);
        }
        $montoRecibido = $request->input('monto_recibido');
        $vueltoCalculado = $montoRecibido === null ? 0 : max(round(((float) $montoRecibido) - $totalCalculado, 2), 0);

        DB::beginTransaction();
        try {
            $correlativo = $this->correlativos->reservar($tipoComprobante);

            try {
                $this->guardarClienteDesdeVenta($request);
            } catch (\Throwable $clienteError) {
                report($clienteError);
            }

            $ventaId = DB::table('ventas')->insertGetId([
                'usuario_id' => $usuario->usuario_id,
                'pedido_id' => $pedidoId,
                'tipo_comprobante' => $tipoComprobante,
                'codigo_tipo_comprobante' => $correlativo['codigo_tipo_comprobante'],
                'serie' => $correlativo['serie'],
                'numero' => $correlativo['numero'],
                'estado_sunat' => $correlativo['codigo_tipo_comprobante'] === 'NV' ? 'NO_APLICA' : 'NO_ENVIADO',
                'fecha_emision' => $request->input('fecha_emision'),
                'moneda' => $request->input('moneda'),
                'forma_pago' => $request->input('forma_pago'),
                'metodo_pago' => $request->input('metodo_pago'),
                'cliente_tipo_documento' => $request->input('cliente_tipo_documento'),
                'cliente_documento' => $request->input('cliente_documento'),
                'cliente_nombre' => $request->input('cliente_nombre'),
                'cliente_direccion' => $request->input('cliente_direccion'),
                'subtotal' => $totalCalculado,
                'operacion_gravada' => 0,
                'operacion_exonerada' => $totalCalculado,
                'operacion_inafecta' => 0,
                'igv' => 0,
                'total_impuestos' => 0,
                'total' => $totalCalculado,
                'monto_recibido' => $montoRecibido,
                'vuelto' => $vueltoCalculado,
                'referencia_serie' => $request->input('referencia_serie'),
                'referencia_numero' => $request->input('referencia_numero'),
                'referencia_motivo' => $request->input('referencia_motivo'),
                'creado_en' => now(),
            ]);

            foreach ($detalles as $detalle) {
                DB::table('venta_detalle')->insert([
                    'comprobante_venta_id' => $ventaId,
                    'descripcion' => $detalle['descripcion'],
                    'unidad' => $detalle['unidad'],
                    'cantidad' => $detalle['cantidad'],
                    'precio_unitario' => $detalle['precio_unitario'],
                    'codigo_unidad' => $detalle['codigo_unidad'],
                    'tipo_afectacion_igv' => $detalle['tipo_afectacion_igv'],
                    'valor_unitario' => $detalle['valor_unitario'],
                    'valor_venta' => $detalle['valor_venta'],
                    'igv' => $detalle['igv'],
                    'total_impuestos' => $detalle['total_impuestos'],
                    'total_linea' => $detalle['total_linea'],
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'message' => 'No se pudo guardar la venta.',
            ], 500);
        }

        $venta = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();

        return response()->json($venta, 201);
    }

    public function prepararDesdePedido(Request $request, Pedido $pedido)
    {
        $this->autorizarFacturacion($request);
        $this->asegurarTablasVenta();

        $pedido->load(['cliente', 'detalles', 'pagos']);
        $comprobante = DB::table('ventas')->where('pedido_id', $pedido->pedido_id)->first();
        $saldo = $this->saldoPedido($pedido);

        return response()->json([
            'pedido_id' => $pedido->pedido_id,
            'tipo_pedido' => $pedido->tipo_pedido,
            'mesa' => $pedido->mesa,
            'total' => round((float) $pedido->total, 2),
            'saldo_pendiente' => $saldo,
            'pagado_completo' => $saldo <= 0,
            'comprobante' => $comprobante,
            'cliente' => $pedido->cliente,
            'detalles' => $pedido->detalles,
        ]);
    }

    public function pdf(Request $request, int $ventaId)
    {
        $this->autorizarFacturacion($request);
        $this->asegurarTablasVenta();

        $formato = (string) $request->query('formato', 'a4');
        if (!in_array($formato, ['a4', 'ticket-80', 'ticket-57'], true)) {
            return response()->json(['message' => 'Formato de comprobante inválido.'], 422);
        }

        [$venta, $detalles] = $this->obtenerVentaConDetalles($ventaId);
        if (!$venta) {
            return response()->json(['message' => 'Venta no encontrada.'], 404);
        }

        $lineas = [
            'POLLO FRESCO S.A.C.',
            strtoupper((string) $venta->tipo_comprobante) . " {$venta->serie}-{$venta->numero}",
            "Fecha: {$venta->fecha_emision}",
            'Cliente: ' . ($venta->cliente_nombre ?: 'Público general'),
            'Documento: ' . ($venta->cliente_documento ?: '-'),
            '----------------------------------------',
        ];

        foreach ($detalles as $detalle) {
            $lineas[] = sprintf('%s x%s %s', $detalle->descripcion, $detalle->cantidad, $detalle->unidad);
            $lineas[] = sprintf('  S/ %.2f', $detalle->total_linea);
        }

        $lineas[] = '----------------------------------------';
        $lineas[] = sprintf('TOTAL: S/ %.2f', $venta->total);
        $lineas[] = 'Gracias por su compra';

        [$ancho, $alto] = match ($formato) {
            'ticket-80' => [226.77, max(320, 40 + (count($lineas) * 14))],
            'ticket-57' => [161.57, max(320, 40 + (count($lineas) * 14))],
            default => [595.28, 841.89],
        };

        $contenido = "BT\n/F1 12 Tf\n14 TL\n20 " . ($alto - 30) . " Td\n";
        foreach ($lineas as $linea) {
            $texto = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $linea);
            $contenido .= "({$texto}) Tj\nT*\n";
        }
        $contenido .= 'ET';

        $pdf = $this->renderSimplePdf($contenido, $ancho, $alto);
        $nombre = "comprobante-{$venta->serie}-{$venta->numero}-{$formato}.pdf";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
        ]);
    }

    public function xml(int $ventaId)
    {
        $this->autorizarFacturacion(request());
        $this->asegurarTablasVenta();

        [$venta, $detalles] = $this->obtenerVentaConDetalles($ventaId);
        if (!$venta) {
            return response()->json(['message' => 'Venta no encontrada.'], 404);
        }

        if (($venta->codigo_tipo_comprobante ?? null) !== 'NV') {
            try {
                if ($venta->xml_firmado_ruta && Storage::disk('local')->exists($venta->xml_firmado_ruta)) {
                    return response(Storage::disk('local')->get($venta->xml_firmado_ruta), 200, [
                        'Content-Type' => 'application/xml; charset=UTF-8',
                        'Content-Disposition' => "attachment; filename=\"{$venta->serie}-{$venta->numero}.xml\"",
                    ]);
                }
                $firmado = $this->facturacionElectronica->generarXmlFirmado($ventaId);

                return response($firmado['contenido'], 200, [
                    'Content-Type' => 'application/xml; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename=\"{$firmado['nombre']}.xml\"",
                ]);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><comprobante></comprobante>');
        $xml->addChild('tipo_comprobante', (string) $venta->tipo_comprobante);
        $xml->addChild('serie', (string) $venta->serie);
        $xml->addChild('numero', (string) $venta->numero);
        $xml->addChild('fecha_emision', (string) $venta->fecha_emision);
        $xml->addChild('moneda', (string) $venta->moneda);
        $xml->addChild('forma_pago', (string) $venta->forma_pago);
        $xml->addChild('metodo_pago', (string) $venta->metodo_pago);

        $cliente = $xml->addChild('cliente');
        $cliente->addChild('tipo_documento', (string) ($venta->cliente_tipo_documento ?? ''));
        $cliente->addChild('documento', (string) ($venta->cliente_documento ?? ''));
        $cliente->addChild('nombre', htmlspecialchars((string) ($venta->cliente_nombre ?? '')));
        $cliente->addChild('direccion', htmlspecialchars((string) ($venta->cliente_direccion ?? '')));

        $items = $xml->addChild('detalles');
        foreach ($detalles as $detalle) {
            $item = $items->addChild('item');
            $item->addChild('descripcion', htmlspecialchars((string) $detalle->descripcion));
            $item->addChild('unidad', (string) $detalle->unidad);
            $item->addChild('cantidad', (string) $detalle->cantidad);
            $item->addChild('precio_unitario', (string) $detalle->precio_unitario);
            $item->addChild('total_linea', (string) $detalle->total_linea);
        }

        $xml->addChild('subtotal', (string) $venta->subtotal);
        $xml->addChild('total', (string) $venta->total);
        $xml->addChild('vuelto', (string) $venta->vuelto);

        $nombre = "comprobante-{$venta->serie}-{$venta->numero}.xml";

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
        ]);
    }

    public function cdr(int $ventaId)
    {
        $this->autorizarFacturacion(request());
        $venta = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();
        if (! $venta) {
            return response()->json(['message' => 'Venta no encontrada.'], 404);
        }
        if (! $venta->cdr_ruta || ! Storage::disk('local')->exists($venta->cdr_ruta)) {
            return response()->json(['message' => 'Este comprobante todavia no tiene un CDR disponible.'], 404);
        }

        return response(Storage::disk('local')->get($venta->cdr_ruta), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"R-{$venta->serie}-{$venta->numero}.zip\"",
        ]);
    }

    private function obtenerVentaConDetalles(int $ventaId): array
    {
        $venta = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();

        $detalles = DB::table('venta_detalle')
            ->where('comprobante_venta_id', $ventaId)
            ->orderBy('comprobante_venta_detalle_id')
            ->get();

        return [$venta, $detalles];
    }

    private function guardarClienteDesdeVenta(Request $request): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        $tipoDocumento = strtolower(trim((string) $request->input('cliente_tipo_documento')));
        $documento = trim((string) $request->input('cliente_documento'));
        $documentoSoloDigitos = preg_replace('/\D+/', '', $documento) ?? '';
        $nombre = trim((string) $request->input('cliente_nombre'));

        if ($documento === '' && $nombre === '') {
            return;
        }

        $direccion = trim((string) $request->input('cliente_direccion'));
        [$nombres, $apellidos] = $this->separarNombreCompleto($nombre);

        $payloadBase = [
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'nombre_empresa' => $nombre !== '' ? $nombre : null,
            'direccion' => $direccion !== '' ? $direccion : null,
            'direccion_fiscal' => $direccion !== '' ? $direccion : null,
            'actualizado_en' => now(),
        ];

        $columnaDocumento = match ($tipoDocumento) {
            'ruc', '6' => 'ruc',
            'dni', '1' => 'dni',
            default => strlen($documentoSoloDigitos) === 11 ? 'ruc' : 'dni',
        };

        $payloadBase = $this->filtrarColumnasExistentes('clientes', $payloadBase);
        if (!$payloadBase) {
            return;
        }

        if ($documento !== '' && Schema::hasColumn('clientes', $columnaDocumento)) {
            $clienteExistente = DB::table('clientes')->where($columnaDocumento, $documento)->first();

            if ($clienteExistente) {
                DB::table('clientes')
                    ->where('cliente_id', $clienteExistente->cliente_id)
                    ->update($payloadBase);

                return;
            }

            $payloadInsert = $this->filtrarColumnasExistentes('clientes', array_merge($payloadBase, [
                'dni' => $columnaDocumento === 'dni' ? $documento : null,
                'ruc' => $columnaDocumento === 'ruc' ? $documento : null,
                'creado_en' => now(),
            ]));

            if ($payloadInsert) {
                DB::table('clientes')->insert($payloadInsert);
            }

            return;
        }

        $payloadSinDocumento = $this->filtrarColumnasExistentes('clientes', array_merge($payloadBase, [
            'dni' => null,
            'ruc' => null,
            'creado_en' => now(),
        ]));

        if ($payloadSinDocumento) {
            DB::table('clientes')->insert($payloadSinDocumento);
        }
    }


    private function filtrarColumnasExistentes(string $tabla, array $payload): array
    {
        $resultado = [];

        foreach ($payload as $columna => $valor) {
            if (Schema::hasColumn($tabla, $columna)) {
                $resultado[$columna] = $valor;
            }
        }

        return $resultado;
    }

    private function separarNombreCompleto(string $nombreCompleto): array
    {
        $partes = preg_split('/\s+/', trim($nombreCompleto)) ?: [];
        if (!$partes) {
            return ['Público', 'general'];
        }

        if (count($partes) === 1) {
            return [$partes[0], ''];
        }

        $apellidos = array_pop($partes) ?: '';
        $nombres = implode(' ', $partes);

        return [$nombres !== '' ? $nombres : 'Cliente', $apellidos];
    }

    private function codigoUnidad(string $unidad): string
    {
        return match (strtoupper(trim($unidad))) {
            'KG', 'KGM' => 'KGM',
            default => 'NIU',
        };
    }

    private function autorizarFacturacion(Request $request): void
    {
        abort_if($request->user()?->role === 'delivery', 403, 'El rol delivery no puede gestionar facturación.');
    }

    private function renderSimplePdf(string $content, float $width, float $height): string
    {
        $objects = [];
        $objects[] = '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj';
        $objects[] = '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj';
        $objects[] = sprintf('3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>endobj', $width, $height);
        $objects[] = "4 0 obj<< /Length " . strlen($content) . " >>stream\n{$content}\nendstream\nendobj";
        $objects[] = '5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }

        $pdf .= 'trailer<< /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= "startxref\n";
        $pdf .= $xrefOffset . "\n";
        $pdf .= '%%EOF';

        return $pdf;
    }

    private function asegurarTablasVenta(): void
    {
        if (!Schema::hasTable('ventas')) {
            Schema::create('ventas', function (Blueprint $table) {
                $table->bigIncrements('comprobante_venta_id');
                $table->unsignedInteger('usuario_id');
                $table->unsignedBigInteger('pedido_id')->nullable()->unique('ventas_pedido_unique');
                $table->string('tipo_comprobante', 20);
                $table->string('serie', 10);
                $table->string('numero', 20);
                $table->date('fecha_emision');
                $table->string('moneda', 10)->default('PEN');
                $table->string('forma_pago', 40)->default('Contado');
                $table->string('metodo_pago', 30)->default('efectivo');
                $table->string('cliente_tipo_documento', 10)->nullable();
                $table->string('cliente_documento', 20)->nullable();
                $table->string('cliente_nombre', 150)->nullable();
                $table->string('cliente_direccion', 255)->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('monto_recibido', 12, 2)->nullable();
                $table->decimal('vuelto', 12, 2)->default(0);
                $table->string('referencia_serie', 10)->nullable();
                $table->string('referencia_numero', 20)->nullable();
                $table->string('referencia_motivo', 255)->nullable();
                $table->timestamp('creado_en')->useCurrent();

                $table->index(['serie', 'numero']);
                $table->index('fecha_emision');
            });
        } else {
            $this->asegurarColumnasTablaVentas();
        }

        if (!Schema::hasTable('venta_detalle')) {
            Schema::create('venta_detalle', function (Blueprint $table) {
                $table->bigIncrements('comprobante_venta_detalle_id');
                $table->unsignedBigInteger('comprobante_venta_id');
                $table->string('descripcion', 120);
                $table->string('unidad', 10)->default('UND');
                $table->decimal('cantidad', 12, 2);
                $table->decimal('precio_unitario', 12, 2);
                $table->decimal('total_linea', 12, 2);

                $table->foreign('comprobante_venta_id', 'fk_cvdet_comprobante')
                    ->references('comprobante_venta_id')
                    ->on('ventas')
                    ->cascadeOnDelete();
            });
        } else {
            $this->asegurarColumnasTablaVentaDetalle();
        }
    }

    private function asegurarColumnasTablaVentas(): void
    {
        $columnas = [
            'pedido_id' => fn (Blueprint $table) => $table->unsignedBigInteger('pedido_id')->nullable()->after('usuario_id'),
            'metodo_pago' => fn (Blueprint $table) => $table->string('metodo_pago', 30)->default('efectivo')->after('forma_pago'),
            'cliente_tipo_documento' => fn (Blueprint $table) => $table->string('cliente_tipo_documento', 10)->nullable()->after('metodo_pago'),
            'cliente_documento' => fn (Blueprint $table) => $table->string('cliente_documento', 20)->nullable()->after('cliente_tipo_documento'),
            'cliente_nombre' => fn (Blueprint $table) => $table->string('cliente_nombre', 150)->nullable()->after('cliente_documento'),
            'cliente_direccion' => fn (Blueprint $table) => $table->string('cliente_direccion', 255)->nullable()->after('cliente_nombre'),
            'monto_recibido' => fn (Blueprint $table) => $table->decimal('monto_recibido', 12, 2)->nullable()->after('total'),
            'vuelto' => fn (Blueprint $table) => $table->decimal('vuelto', 12, 2)->default(0)->after('monto_recibido'),
            'referencia_serie' => fn (Blueprint $table) => $table->string('referencia_serie', 10)->nullable()->after('vuelto'),
            'referencia_numero' => fn (Blueprint $table) => $table->string('referencia_numero', 20)->nullable()->after('referencia_serie'),
            'referencia_motivo' => fn (Blueprint $table) => $table->string('referencia_motivo', 255)->nullable()->after('referencia_numero'),
            'creado_en' => fn (Blueprint $table) => $table->timestamp('creado_en')->useCurrent()->after('referencia_motivo'),
        ];

        foreach ($columnas as $columna => $callback) {
            if (!Schema::hasColumn('ventas', $columna)) {
                Schema::table('ventas', $callback);
            }
        }

        if (Schema::hasColumn('ventas', 'pedido_id')) {
            try {
                Schema::table('ventas', fn (Blueprint $table) => $table->unique('pedido_id', 'ventas_pedido_unique'));
            } catch (\Throwable) {
                // El indice ya existe.
            }
        }
    }

    private function saldoPedido(Pedido $pedido): float
    {
        $pagado = $pedido->pagos
            ->where('estado_pago', '<>', 'PENDIENTE')
            ->sum(fn ($pago) => (float) ($pago->pago_parcial ?? 0));

        return max(0, round((float) $pedido->total - $pagado, 2));
    }

    private function asegurarColumnasTablaVentaDetalle(): void
    {
        $columnas = [
            'descripcion' => fn (Blueprint $table) => $table->string('descripcion', 120)->default('')->after('comprobante_venta_id'),
            'unidad' => fn (Blueprint $table) => $table->string('unidad', 10)->default('UND')->after('descripcion'),
            'cantidad' => fn (Blueprint $table) => $table->decimal('cantidad', 12, 2)->default(0)->after('unidad'),
            'precio_unitario' => fn (Blueprint $table) => $table->decimal('precio_unitario', 12, 2)->default(0)->after('cantidad'),
            'total_linea' => fn (Blueprint $table) => $table->decimal('total_linea', 12, 2)->default(0)->after('precio_unitario'),
        ];

        foreach ($columnas as $columna => $callback) {
            if (!Schema::hasColumn('venta_detalle', $columna)) {
                Schema::table('venta_detalle', $callback);
            }
        }
    }
}
