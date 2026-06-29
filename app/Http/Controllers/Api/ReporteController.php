<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function inicio(Request $request)
    {
        [$desde, $hasta] = $this->resolverRango($request);
        $datos = $this->resumen($request)->getData(true);
        $resumen = $datos['resumen'];

        $ventasDiarias = $this->ventasPorDia($desde, $hasta);
        $productos = $this->productosMasVendidos($desde, $hasta);
        $stockBajo = collect($datos['stock'])->where('alerta', true)->take(5)->values();
        $mayoresDeudores = collect($datos['cuentas_cobrar'])->take(5)->values();
        $pedidosActivos = (int) DB::table('pedidos')->whereIn('estado_id', [1, 4])->count();
        $pedidosEnRuta = (int) DB::table('pedidos')->where('estado_id', 4)->count();
        $clientesNuevos = (int) DB::table('clientes')->whereBetween(DB::raw('DATE(creado_en)'), [$desde, $hasta])->count();
        $ventasGeneradas = round(
            (float) $resumen['ventas_pedidos']
            + (float) $resumen['ventas_otros_productos']
            + (float) $resumen['ventas_pollo_gallina'],
            2
        );
        $margen = $ventasGeneradas > 0 ? round(((float) $resumen['ganancia_bruta'] / $ventasGeneradas) * 100, 1) : 0;
        $dineroCobrado = round((float) ($resumen['dinero_cobrado'] ?? 0), 2);
        $pendientePeriodo = round((float) ($resumen['cuentas_por_cobrar'] ?? 0), 2);
        $porcentajeCobrado = $ventasGeneradas > 0 ? round((min($dineroCobrado, $ventasGeneradas) / $ventasGeneradas) * 100, 1) : 0;

        return response()->json([
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'indicadores' => array_merge($resumen, [
                'ventas_generadas' => $ventasGeneradas,
                'total_facturado' => $ventasGeneradas,
                'margen_porcentaje' => $margen,
                'porcentaje_cobrado' => max(0, min(100, $porcentajeCobrado)),
                'cobrado_periodo' => $dineroCobrado,
                'dinero_cobrado' => $dineroCobrado,
                'pendiente_periodo' => $pendientePeriodo,
                'pedidos_activos' => $pedidosActivos,
                'pedidos_en_ruta' => $pedidosEnRuta,
                'clientes_nuevos' => $clientesNuevos,
            ]),
            'ventas_diarias' => $ventasDiarias,
            'productos_mas_vendidos' => $productos,
            'stock_bajo' => $stockBajo,
            'mayores_deudores' => $mayoresDeudores,
            'delivery' => collect($datos['delivery'])->take(5)->values(),
            'distribucion' => [
                ['nombre' => 'Pedidos', 'valor' => round((float) $resumen['ventas_pedidos'], 2), 'color' => '#2455df'],
                ['nombre' => 'Otros productos', 'valor' => round((float) $resumen['ventas_otros_productos'], 2), 'color' => '#0f9f75'],
                ['nombre' => 'Pollo + gallina', 'valor' => round((float) $resumen['ventas_pollo_gallina'], 2), 'color' => '#e08a19'],
            ],
        ]);
    }

    public function resumen(Request $request)
    {
        [$desde, $hasta] = $this->resolverRango($request);

        $ventasPedidos = $this->ventasPedidos($desde, $hasta);
        $ventasOtros = $this->ventasOtros($desde, $hasta);
        $gastos = $this->gastos($desde, $hasta);
        $cuentas = $this->cuentasPorCobrar();
        $proveedores = $this->proveedores($desde, $hasta);
        $delivery = $this->delivery($desde, $hasta);
        $stock = $this->stock();
        $auditoria = $this->auditoria($desde, $hasta);
        $polloGallina = $this->resumenPolloGallina($desde, $hasta);

        $totalPedidos = round((float) $ventasPedidos->sum('total'), 2);
        $totalOtros = round((float) $ventasOtros->sum('total'), 2);
        $costoOtros = round((float) $ventasOtros->sum('costo'), 2);
        $totalGastos = round((float) $gastos->where('estado', 'ACTIVO')->sum('monto'), 2);
        $gananciaBruta = round($polloGallina['ganancia'] + $totalOtros - $costoOtros, 2);
        $dineroCobradoPedidos = $this->pagosPedidosEnPeriodo($desde, $hasta);
        $dineroCobrado = round($dineroCobradoPedidos + $totalOtros + $polloGallina['ventas'], 2);
        $ventasGeneradas = round($totalPedidos + $totalOtros + $polloGallina['ventas'], 2);

        return response()->json([
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'resumen' => [
                'ventas_generadas' => $ventasGeneradas,
                'ventas_pedidos' => $totalPedidos,
                'ventas_otros_productos' => $totalOtros,
                'ventas_pollo_gallina' => $polloGallina['ventas'],
                'costos_pollo_gallina' => $polloGallina['costos'],
                'costos_otros_productos' => $costoOtros,
                'ganancia_bruta' => $gananciaBruta,
                'gastos' => $totalGastos,
                'resultado_neto' => round($gananciaBruta - $totalGastos, 2),
                'dinero_cobrado' => $dineroCobrado,
                'cobrado_periodo' => $dineroCobrado,
                'cobrado_pedidos' => $dineroCobradoPedidos,
                'cobrado_otros_productos' => $totalOtros,
                'cobrado_pollo_gallina' => $polloGallina['ventas'],
                'cuentas_por_cobrar' => round((float) $cuentas->sum('saldo'), 2),
                'deuda_proveedores' => round((float) $proveedores->sum('pendiente'), 2),
                'valor_stock' => round((float) $stock->sum('valor_stock'), 2),
                'pedidos_registrados' => $ventasPedidos->count(),
            ],
            'ventas' => $this->combinarVentas($ventasPedidos, $ventasOtros),
            'stock' => $stock->values(),
            'cuentas_cobrar' => $cuentas->values(),
            'proveedores' => $proveedores->values(),
            'delivery' => $delivery->values(),
            'gastos' => $gastos->values(),
            'auditoria' => $auditoria->values(),
        ]);
    }

    public function pdf(Request $request)
    {
        $vista = (string) $request->query('vista', 'resumen');
        $vistasPermitidas = ['resumen', 'ventas', 'stock', 'cobrar', 'proveedores', 'delivery', 'gastos', 'auditoria'];
        abort_unless(in_array($vista, $vistasPermitidas, true), 422, 'Tipo de reporte no valido.');

        $datos = $this->resumen($request)->getData(true);
        $titulos = [
            'resumen' => 'Resumen ejecutivo',
            'ventas' => 'Ventas y ganancias',
            'stock' => 'Stock y lotes',
            'cobrar' => 'Cuentas por cobrar',
            'proveedores' => 'Compras a proveedores',
            'delivery' => 'Rendimiento delivery',
            'gastos' => 'Gastos del periodo',
            'auditoria' => 'Auditoria financiera',
        ];

        return Pdf::loadView('pdf.reporte-general', [
            'vista' => $vista,
            'titulo' => $titulos[$vista],
            'datos' => $datos,
            'generadoEn' => Carbon::now('America/Lima')->format('d/m/Y H:i'),
        ])
            ->setPaper('a4', 'landscape')
            ->download("reporte-{$vista}-{$datos['fecha_desde']}-{$datos['fecha_hasta']}.pdf");
    }

    private function ventasPedidos(string $desde, string $hasta): Collection
    {
        $pagos = DB::table('pedido_pagos')
            ->select('pedido_id')
            ->selectRaw('SUM(COALESCE(pago_parcial, 0)) as pagado')
            ->groupBy('pedido_id');

        return DB::table('pedidos as p')
            ->leftJoin('clientes as c', 'c.cliente_id', '=', 'p.cliente_id')
            ->leftJoin('pedido_estados as pe', 'pe.estado_id', '=', 'p.estado_id')
            ->leftJoinSub($pagos, 'pp', fn ($join) => $join->on('pp.pedido_id', '=', 'p.pedido_id'))
            ->whereBetween(DB::raw('DATE(p.fecha_hora_creacion)'), [$desde, $hasta])
            ->select([
                'p.pedido_id',
                'p.fecha_hora_creacion',
                'p.tipo_pedido',
                'p.mesa',
                'p.total',
                'pe.nombre as estado',
                'c.nombres',
                'c.apellidos',
                'c.nombre_empresa',
            ])
            ->selectRaw('LEAST(p.total, COALESCE(pp.pagado, 0)) as pagado')
            ->orderByDesc('p.fecha_hora_creacion')
            ->get()
            ->map(function ($item) {
                $cliente = trim((string) ($item->nombre_empresa ?: ($item->nombres . ' ' . $item->apellidos)));
                $pagado = round((float) $item->pagado, 2);
                $total = round((float) $item->total, 2);

                return [
                    'id' => (int) $item->pedido_id,
                    'fecha' => $item->fecha_hora_creacion,
                    'origen' => 'PEDIDO',
                    'tipo' => $item->tipo_pedido ?: 'PEDIDO',
                    'cliente_producto' => $cliente !== '' ? $cliente : 'Cliente no registrado',
                    'detalle' => $item->mesa ? 'Mesa ' . $item->mesa : ($item->estado ?? 'Sin estado'),
                    'total' => $total,
                    'costo' => 0,
                    'ganancia' => 0,
                    'pagado' => $pagado,
                    'saldo' => round(max(0, $total - $pagado), 2),
                    'estado' => $item->estado ?? 'SIN ESTADO',
                ];
            });
    }

    private function ventasOtros(string $desde, string $hasta): Collection
    {
        $costos = DB::table('otros_productos_venta_lotes_consumos')
            ->select('venta_op_diaria_id')
            ->selectRaw('SUM(costo_total) as costo_total')
            ->groupBy('venta_op_diaria_id');

        // Respaldo para ventas antiguas creadas antes de guardar el lote consumido.
        $costoPromedioProducto = DB::table('compras_lote_detalle')
            ->select('producto_id')
            ->selectRaw("
                SUM(COALESCE(costo_total_compra, costo_kilo * COALESCE(cantidad_presentacion * factor_conversion, cantidad)))
                / NULLIF(SUM(COALESCE(cantidad_presentacion * factor_conversion, cantidad)), 0) as costo_unitario
            ")
            ->groupBy('producto_id');

        return DB::table('otros_productos_ventas_diarias as v')
            ->join('productos as p', 'p.producto_id', '=', 'v.producto_id')
            ->leftJoin('usuarios as u', 'u.usuario_id', '=', 'v.usuario_id')
            ->leftJoin('compras_lote_detalle as d', 'd.compra_lote_detalle_id', '=', 'v.compra_lote_detalle_id')
            ->leftJoinSub($costos, 'vc', fn ($join) => $join->on('vc.venta_op_diaria_id', '=', 'v.venta_op_diaria_id'))
            ->leftJoinSub($costoPromedioProducto, 'cp', fn ($join) => $join->on('cp.producto_id', '=', 'v.producto_id'))
            ->whereBetween(DB::raw('DATE(v.fecha_hora)'), [$desde, $hasta])
            ->select([
                'v.venta_op_diaria_id',
                'v.fecha_hora',
                'v.cantidad',
                'v.presentacion_venta',
                'v.total',
                'p.nombre as producto',
                'p.grupo_venta',
                'u.nombres',
                'u.apellidos',
            ])
            ->selectRaw("
                COALESCE(
                    vc.costo_total,
                    CASE
                        WHEN d.compra_lote_detalle_id IS NULL THEN NULL
                        WHEN p.grupo_venta = 'HUEVOS'
                            AND COALESCE(d.cantidad_presentacion * d.factor_conversion, 0) > 0
                        THEN v.cantidad * (COALESCE(d.costo_total_compra, 0) / (d.cantidad_presentacion * d.factor_conversion))
                        ELSE v.cantidad * COALESCE(d.costo_kilo, 0)
                    END,
                    v.cantidad * cp.costo_unitario,
                    0
                ) as costo
            ")
            ->orderByDesc('v.fecha_hora')
            ->get()
            ->map(function ($item) {
                $total = round((float) $item->total, 2);
                $costo = round((float) $item->costo, 2);

                return [
                    'id' => (int) $item->venta_op_diaria_id,
                    'fecha' => $item->fecha_hora,
                    'origen' => 'OTROS_PRODUCTOS',
                    'tipo' => $item->grupo_venta,
                    'cliente_producto' => $item->producto,
                    'detalle' => $this->numero($item->cantidad) . ' ' . ($item->presentacion_venta ?: 'unidad base'),
                    'total' => $total,
                    'costo' => $costo,
                    'ganancia' => round($total - $costo, 2),
                    'pagado' => $total,
                    'saldo' => 0,
                    'estado' => 'REGISTRADA',
                    'usuario' => trim((string) ($item->nombres . ' ' . $item->apellidos)),
                ];
            });
    }

    private function pagosPedidosEnPeriodo(string $desde, string $hasta): float
    {
        return round((float) DB::table('pedido_pagos')
            ->whereBetween(DB::raw('DATE(fecha_hora)'), [$desde, $hasta])
            ->sum('pago_parcial'), 2);
    }

    private function combinarVentas(Collection $pedidos, Collection $otros): Collection
    {
        return $pedidos
            ->concat($otros)
            ->sortByDesc('fecha')
            ->take(300)
            ->values();
    }

    private function stock(): Collection
    {
        return DB::table('compras_lote_detalle as d')
            ->join('compras_lote as l', 'l.compra_lote_id', '=', 'd.compra_lote_id')
            ->join('productos as p', 'p.producto_id', '=', 'd.producto_id')
            ->select(['p.producto_id', 'p.nombre', 'p.grupo_venta'])
            ->selectRaw('SUM(d.cantidad) as stock')
            ->selectRaw('COUNT(DISTINCT CASE WHEN d.cantidad > 0 THEN l.compra_lote_id END) as lotes_abiertos')
            ->selectRaw("
                SUM(
                    d.cantidad * CASE
                        WHEN p.grupo_venta = 'HUEVOS'
                            AND COALESCE(d.cantidad_presentacion * d.factor_conversion, 0) > 0
                        THEN COALESCE(d.costo_total_compra, 0) / (d.cantidad_presentacion * d.factor_conversion)
                        ELSE COALESCE(d.costo_kilo, 0)
                    END
                ) as valor_stock
            ")
            ->groupBy('p.producto_id', 'p.nombre', 'p.grupo_venta')
            ->orderBy('p.grupo_venta')
            ->orderBy('p.nombre')
            ->get()
            ->map(fn ($item) => [
                'producto_id' => (int) $item->producto_id,
                'producto' => $item->nombre,
                'grupo' => $item->grupo_venta,
                'stock' => round((float) $item->stock, 2),
                'unidad' => $item->grupo_venta === 'HUEVOS' ? 'huevos' : 'kg',
                'lotes_abiertos' => (int) $item->lotes_abiertos,
                'valor_stock' => round((float) $item->valor_stock, 2),
                'alerta' => (float) $item->stock <= 5,
            ]);
    }

    private function cuentasPorCobrar(): Collection
    {
        $pagos = DB::table('pedido_pagos')
            ->select('pedido_id')
            ->selectRaw('SUM(COALESCE(pago_parcial, 0)) as pagado')
            ->groupBy('pedido_id');

        return DB::table('pedidos as p')
            ->leftJoin('clientes as c', 'c.cliente_id', '=', 'p.cliente_id')
            ->leftJoinSub($pagos, 'pp', fn ($join) => $join->on('pp.pedido_id', '=', 'p.pedido_id'))
            ->where('p.estado_id', '<>', 3)
            ->select(['c.cliente_id', 'c.nombres', 'c.apellidos', 'c.nombre_empresa', 'c.celular'])
            ->selectRaw('COUNT(p.pedido_id) as pedidos')
            ->selectRaw('SUM(p.total) as total')
            ->selectRaw('SUM(LEAST(p.total, COALESCE(pp.pagado, 0))) as pagado')
            ->selectRaw('SUM(GREATEST(p.total - COALESCE(pp.pagado, 0), 0)) as saldo')
            ->selectRaw('MIN(CASE WHEN p.total > COALESCE(pp.pagado, 0) THEN DATE(p.fecha_hora_creacion) END) as deuda_desde')
            ->groupBy('c.cliente_id', 'c.nombres', 'c.apellidos', 'c.nombre_empresa', 'c.celular')
            ->havingRaw('saldo > 0')
            ->orderByDesc('saldo')
            ->get()
            ->map(fn ($item) => [
                'cliente_id' => $item->cliente_id ? (int) $item->cliente_id : null,
                'cliente' => trim((string) ($item->nombre_empresa ?: ($item->nombres . ' ' . $item->apellidos))) ?: 'Cliente no registrado',
                'celular' => $item->celular,
                'pedidos' => (int) $item->pedidos,
                'total' => round((float) $item->total, 2),
                'pagado' => round((float) $item->pagado, 2),
                'saldo' => round((float) $item->saldo, 2),
                'deuda_desde' => $item->deuda_desde,
            ]);
    }

    private function proveedores(string $desde, string $hasta): Collection
    {
        return DB::table('entregas_proveedor as e')
            ->join('proveedores as p', 'p.proveedor_id', '=', 'e.proveedor_id')
            ->whereBetween(DB::raw('DATE(e.fecha_hora)'), [$desde, $hasta])
            ->select(['p.proveedor_id', 'p.nombres', 'p.apellidos', 'p.nombre_empresa', 'p.telefono'])
            ->selectRaw('COUNT(e.entrega_id) as entregas')
            ->selectRaw('SUM(e.costo_total) as compras')
            ->selectRaw("SUM(CASE WHEN UPPER(e.estado_pago) = 'PAGADO' THEN 0 ELSE e.costo_total END) as pendiente")
            ->selectRaw('MAX(e.fecha_hora) as ultima_entrega')
            ->groupBy('p.proveedor_id', 'p.nombres', 'p.apellidos', 'p.nombre_empresa', 'p.telefono')
            ->orderByDesc('compras')
            ->get()
            ->map(fn ($item) => [
                'proveedor_id' => (int) $item->proveedor_id,
                'proveedor' => trim((string) ($item->nombre_empresa ?: ($item->nombres . ' ' . $item->apellidos))),
                'telefono' => $item->telefono,
                'entregas' => (int) $item->entregas,
                'compras' => round((float) $item->compras, 2),
                'pendiente' => round((float) $item->pendiente, 2),
                'ultima_entrega' => $item->ultima_entrega,
            ]);
    }

    private function delivery(string $desde, string $hasta): Collection
    {
        return DB::table('pedidos as p')
            ->join('usuarios as u', 'u.usuario_id', '=', 'p.delivery_usuario_id')
            ->whereBetween(DB::raw('DATE(p.fecha_hora_creacion)'), [$desde, $hasta])
            ->select(['u.usuario_id', 'u.nombres', 'u.apellidos', 'u.telefono'])
            ->selectRaw('COUNT(p.pedido_id) as asignados')
            ->selectRaw('SUM(CASE WHEN p.estado_id = 2 THEN 1 ELSE 0 END) as entregados')
            ->selectRaw('SUM(CASE WHEN p.estado_id = 5 THEN 1 ELSE 0 END) as no_entregados')
            ->selectRaw('SUM(CASE WHEN p.estado_id = 2 THEN p.total ELSE 0 END) as total_entregado')
            ->selectRaw('AVG(CASE WHEN p.fecha_hora_entrega IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, p.fecha_hora_creacion, p.fecha_hora_entrega) END) as minutos_promedio')
            ->groupBy('u.usuario_id', 'u.nombres', 'u.apellidos', 'u.telefono')
            ->orderByDesc('entregados')
            ->get()
            ->map(fn ($item) => [
                'usuario_id' => (int) $item->usuario_id,
                'delivery' => trim((string) ($item->nombres . ' ' . $item->apellidos)),
                'telefono' => $item->telefono,
                'asignados' => (int) $item->asignados,
                'entregados' => (int) $item->entregados,
                'no_entregados' => (int) $item->no_entregados,
                'efectividad' => (int) $item->asignados > 0 ? round(((int) $item->entregados / (int) $item->asignados) * 100, 1) : 0,
                'total_entregado' => round((float) $item->total_entregado, 2),
                'minutos_promedio' => $item->minutos_promedio ? round((float) $item->minutos_promedio) : null,
            ]);
    }

    private function gastos(string $desde, string $hasta): Collection
    {
        return DB::table('gastos as g')
            ->leftJoin('gasto_categorias as c', 'c.categoria_id', '=', 'g.categoria_id')
            ->leftJoin('usuarios as u', 'u.usuario_id', '=', 'g.usuario_id')
            ->whereBetween('g.fecha', [$desde, $hasta])
            ->select(['g.gasto_id', 'g.fecha', 'g.fondo', 'g.descripcion', 'g.monto', 'g.estado', 'g.nota', 'c.nombre as categoria', 'u.nombres', 'u.apellidos'])
            ->orderByDesc('g.fecha')
            ->orderByDesc('g.gasto_id')
            ->limit(300)
            ->get()
            ->map(fn ($item) => [
                'gasto_id' => (int) $item->gasto_id,
                'fecha' => $item->fecha,
                'fondo' => $item->fondo,
                'categoria' => $item->categoria ?: 'Sin categoria',
                'descripcion' => $item->descripcion,
                'monto' => round((float) $item->monto, 2),
                'estado' => $item->estado,
                'nota' => $item->nota,
                'usuario' => trim((string) ($item->nombres . ' ' . $item->apellidos)),
            ]);
    }

    private function auditoria(string $desde, string $hasta): Collection
    {
        return DB::table('gasto_auditoria as a')
            ->leftJoin('usuarios as u', 'u.usuario_id', '=', 'a.usuario_id')
            ->whereBetween(DB::raw('DATE(a.creado_en)'), [$desde, $hasta])
            ->select(['a.auditoria_id', 'a.accion', 'a.entidad', 'a.entidad_id', 'a.fondo', 'a.descripcion', 'a.creado_en', 'u.nombres', 'u.apellidos'])
            ->orderByDesc('a.creado_en')
            ->limit(300)
            ->get()
            ->map(fn ($item) => [
                'auditoria_id' => (int) $item->auditoria_id,
                'fecha' => $item->creado_en,
                'accion' => $item->accion,
                'entidad' => $item->entidad,
                'entidad_id' => $item->entidad_id,
                'fondo' => $item->fondo,
                'descripcion' => $item->descripcion,
                'usuario' => trim((string) ($item->nombres . ' ' . $item->apellidos)) ?: 'Sistema',
            ]);
    }

    private function resumenPolloGallina(string $desde, string $hasta): array
    {
        $ventas = (float) DB::table('ventas_pollo_gallina_diarias')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(venta_pollo + venta_gallina), 0) as total')
            ->value('total');

        $costos = (float) DB::table('entregas_proveedor')
            ->whereBetween(DB::raw('DATE(fecha_hora)'), [$desde, $hasta])
            ->where(fn ($query) => $query->whereRaw('UPPER(tipo) LIKE ?', ['%POLLO%'])->orWhereRaw('UPPER(tipo) LIKE ?', ['%GALLINA%']))
            ->sum('costo_total');

        return [
            'ventas' => round($ventas, 2),
            'costos' => round($costos, 2),
            'ganancia' => round($ventas - $costos, 2),
        ];
    }

    private function ventasPorDia(string $desde, string $hasta): Collection
    {
        $pedidos = DB::table('pedidos')
            ->whereBetween(DB::raw('DATE(fecha_hora_creacion)'), [$desde, $hasta])
            ->selectRaw('DATE(fecha_hora_creacion) as fecha, SUM(total) as total')
            ->groupBy(DB::raw('DATE(fecha_hora_creacion)'))
            ->pluck('total', 'fecha');

        $otros = DB::table('otros_productos_ventas_diarias')
            ->whereBetween(DB::raw('DATE(fecha_hora)'), [$desde, $hasta])
            ->selectRaw('DATE(fecha_hora) as fecha, SUM(total) as total')
            ->groupBy(DB::raw('DATE(fecha_hora)'))
            ->pluck('total', 'fecha');

        $pollo = DB::table('ventas_pollo_gallina_diarias')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('fecha, SUM(venta_pollo + venta_gallina) as total')
            ->groupBy('fecha')
            ->pluck('total', 'fecha');

        $inicio = Carbon::parse($desde);
        $fin = Carbon::parse($hasta);
        $dias = min($inicio->diffInDays($fin) + 1, 31);

        return collect(range(0, $dias - 1))->map(function ($indice) use ($inicio, $pedidos, $otros, $pollo) {
            $fecha = $inicio->copy()->addDays($indice)->toDateString();

            return [
                'fecha' => $fecha,
                'etiqueta' => Carbon::parse($fecha)->format('d/m'),
                'total' => round((float) ($pedidos[$fecha] ?? 0) + (float) ($otros[$fecha] ?? 0) + (float) ($pollo[$fecha] ?? 0), 2),
            ];
        });
    }

    private function productosMasVendidos(string $desde, string $hasta): Collection
    {
        return DB::table('otros_productos_ventas_diarias as v')
            ->join('productos as p', 'p.producto_id', '=', 'v.producto_id')
            ->whereBetween(DB::raw('DATE(v.fecha_hora)'), [$desde, $hasta])
            ->select(['p.nombre', 'p.grupo_venta'])
            ->selectRaw('SUM(v.cantidad) as cantidad')
            ->selectRaw('SUM(v.total) as total')
            ->groupBy('p.producto_id', 'p.nombre', 'p.grupo_venta')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($item) => [
                'producto' => $item->nombre,
                'grupo' => $item->grupo_venta,
                'cantidad' => round((float) $item->cantidad, 2),
                'total' => round((float) $item->total, 2),
            ]);
    }

    private function resolverRango(Request $request): array
    {
        $hoy = Carbon::now('America/Lima');
        $desde = Carbon::parse($request->query('fecha_desde', $hoy->copy()->startOfMonth()->toDateString()))->toDateString();
        $hasta = Carbon::parse($request->query('fecha_hasta', $hoy->copy()->endOfMonth()->toDateString()))->toDateString();

        return $desde <= $hasta ? [$desde, $hasta] : [$hasta, $desde];
    }

    private function numero(mixed $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.');
    }
}
