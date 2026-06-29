<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class OtrosProductosController extends Controller
{
    private const PRESENTACIONES_HUEVO = [
        'UNIDAD' => 1,
        'MEDIO_CASILLERO' => 15,
        'CASILLERO' => 30,
        'MEDIA_JAVA' => 180,
        'JAVA' => 360,
    ];

    public function lotesIndex(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $lotes = DB::table('compras_lote as cl')
            ->join('compras_lote_detalle as cld', 'cl.compra_lote_id', '=', 'cld.compra_lote_id')
            ->join('productos as p', 'cld.producto_id', '=', 'p.producto_id')
            ->leftJoin('proveedores as pr', 'cl.proveedor_id', '=', 'pr.proveedor_id')
            ->select([
                'cl.compra_lote_id',
                'cl.proveedor_id',
                'cl.codigo_comprobante',
                'cl.fecha_ingreso',
                'cl.creado_en',
                'cl.estado',
                'p.producto_id',
                'p.nombre as producto_nombre',
                'cld.cantidad',
                Schema::hasColumn('compras_lote_detalle', 'presentacion_ingreso') ? 'cld.presentacion_ingreso' : DB::raw('NULL as presentacion_ingreso'),
                Schema::hasColumn('compras_lote_detalle', 'cantidad_presentacion') ? 'cld.cantidad_presentacion' : DB::raw('NULL as cantidad_presentacion'),
                Schema::hasColumn('compras_lote_detalle', 'factor_conversion') ? 'cld.factor_conversion' : DB::raw('NULL as factor_conversion'),
                'cld.costo_kilo',
                Schema::hasColumn('compras_lote_detalle', 'costo_total_compra') ? 'cld.costo_total_compra' : DB::raw('NULL as costo_total_compra'),
                'cld.precio_venta',
                'pr.nombres as proveedor_nombres',
                'pr.apellidos as proveedor_apellidos',
                'pr.nombre_empresa as proveedor_nombre_empresa',
                'pr.ruc as proveedor_ruc',
                'pr.dni as proveedor_dni',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY p.producto_id ORDER BY cl.compra_lote_id) as numero_lote'),
            ])
            ->orderByDesc('cl.compra_lote_id')
            ->get();

        return response()->json($lotes);
    }

    public function productosIndex(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $termino = trim((string) $request->query('buscar', ''));
        $incluirInactivos = filter_var($request->query('incluir_inactivos', false), FILTER_VALIDATE_BOOLEAN);
        $stockAbierto = DB::table('compras_lote_detalle as cld')
            ->join('compras_lote as cl', 'cl.compra_lote_id', '=', 'cld.compra_lote_id')
            ->where('cl.estado', 'ABIERTO')
            ->groupBy('cld.producto_id')
            ->select('cld.producto_id', DB::raw('SUM(cld.cantidad) as stock_disponible'));

        $productos = DB::table('productos as p')
            ->leftJoinSub($stockAbierto, 'stock_abierto', function ($join) {
                $join->on('stock_abierto.producto_id', '=', 'p.producto_id');
            })
            ->select(
                'p.producto_id as id',
                'p.nombre',
                'p.grupo_venta',
                'p.activo',
                DB::raw('COALESCE(stock_abierto.stock_disponible, 0) as stock_disponible')
            )
            ->when(!$incluirInactivos, function ($query) {
                $query->where('p.activo', 1);
            })
            ->when($termino !== '', function ($query) use ($termino) {
                $query->where('p.nombre', 'like', '%' . $termino . '%');
            })
            ->orderBy('p.nombre')
            ->get();

        return response()->json($productos);
    }

    public function productosStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:80'],
            'grupo_venta' => ['required', 'in:HUEVOS,CONGELADO,OTROS'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $nombre = trim($request->input('nombre'));
        $grupoVenta = strtoupper((string) $request->input('grupo_venta'));
        $productoId = DB::table('productos')->insertGetId([
            'nombre' => $nombre,
            'grupo_venta' => $grupoVenta,
            'activo' => 1,
        ]);

        return response()->json([
            'id' => $productoId,
            'nombre' => $nombre,
            'grupo_venta' => $grupoVenta,
            'activo' => 1,
        ], 201);
    }

    public function productosUpdate(Request $request, int $productoId)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:80'],
            'grupo_venta' => ['required', 'in:HUEVOS,CONGELADO,OTROS'],
            'activo' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $producto = DB::table('productos')->where('producto_id', $productoId)->first();
        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $nombre = trim($request->input('nombre'));
        $grupoVenta = strtoupper((string) $request->input('grupo_venta'));
        $activo = $request->has('activo') ? (int) $request->boolean('activo') : (int) $producto->activo;

        DB::table('productos')
            ->where('producto_id', $productoId)
            ->update([
                'nombre' => $nombre,
                'grupo_venta' => $grupoVenta,
                'activo' => $activo,
            ]);

        return response()->json([
            'id' => $productoId,
            'nombre' => $nombre,
            'grupo_venta' => $grupoVenta,
            'activo' => $activo,
        ]);
    }

    public function productosDestroy(Request $request, int $productoId)
    {
        $producto = DB::table('productos')->where('producto_id', $productoId)->first();
        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        DB::table('productos')
            ->where('producto_id', $productoId)
            ->delete();

        return response()->json([
            'id' => $productoId,
            'nombre' => $producto->nombre,
        ]);
    }

    public function lotesStore(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $validator = Validator::make($request->all(), [
            'producto_id' => ['required', 'integer', 'exists:productos,producto_id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
            'costo_kilo' => ['required', 'numeric', 'min:0'],
            'costo_total_compra' => ['nullable', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'presentacion_ingreso' => ['nullable', 'string', 'max:30'],
            'cantidad_presentacion' => ['nullable', 'numeric', 'min:0.01'],
            'codigo_comprobante' => ['required', 'string', 'max:50'],
            'fecha_ingreso' => ['required', 'date'],
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,proveedor_id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $usuario = $request->user();
        if (!$usuario) {
            return response()->json(['message' => 'Usuario no autenticado'], 401);
        }

        $productoId = (int) $request->input('producto_id');
        $cantidad = (float) $request->input('cantidad');
        $costoKilo = (float) $request->input('costo_kilo');
        $costoTotalCompra = $request->filled('costo_total_compra') ? (float) $request->input('costo_total_compra') : null;
        $precioVenta = (float) $request->input('precio_venta');
        $codigoComprobante = trim((string) $request->input('codigo_comprobante'));
        $fecha = $request->input('fecha_ingreso');
        $proveedorId = (int) $request->input('proveedor_id');

        $producto = DB::table('productos')->where('producto_id', $productoId)->first();
        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $esHuevo = ($producto->grupo_venta ?? '') === 'HUEVOS';
        $presentacionIngreso = $esHuevo ? strtoupper((string) $request->input('presentacion_ingreso', 'UNIDAD')) : null;
        $factorConversion = $esHuevo ? $this->factorPresentacionHuevo($presentacionIngreso) : null;
        if ($esHuevo && $factorConversion === null) {
            return response()->json(['message' => 'Presentacion de huevo no valida.'], 422);
        }
        $cantidadPresentacion = $esHuevo ? (float) $request->input('cantidad_presentacion', $cantidad) : null;
        if ($esHuevo) {
            $cantidad = round($cantidadPresentacion * $factorConversion, 2);
        }
        if ($costoTotalCompra === null) {
            $costoTotalCompra = round($cantidad * $costoKilo, 2);
        }

        $numeroLote = (int) DB::table('compras_lote_detalle')
            ->where('producto_id', $productoId)
            ->count() + 1;

        DB::beginTransaction();
        try {
            $compraLoteId = DB::table('compras_lote')->insertGetId([
                'usuario_id' => $usuario->usuario_id,
                'proveedor_id' => $proveedorId,
                'codigo_comprobante' => $codigoComprobante,
                'fecha_ingreso' => $fecha,
                'estado' => 'ABIERTO',
                'creado_en' => now(),
            ]);

            $detalle = [
                'compra_lote_id' => $compraLoteId,
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'costo_kilo' => $costoKilo,
                'precio_venta' => $precioVenta,
            ];

            if (Schema::hasColumn('compras_lote_detalle', 'presentacion_ingreso')) {
                $detalle['presentacion_ingreso'] = $presentacionIngreso;
            }
            if (Schema::hasColumn('compras_lote_detalle', 'cantidad_presentacion')) {
                $detalle['cantidad_presentacion'] = $cantidadPresentacion;
            }
            if (Schema::hasColumn('compras_lote_detalle', 'factor_conversion')) {
                $detalle['factor_conversion'] = $factorConversion;
            }
            if (Schema::hasColumn('compras_lote_detalle', 'costo_total_compra')) {
                $detalle['costo_total_compra'] = $costoTotalCompra;
            }

            DB::table('compras_lote_detalle')->insert($detalle);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'No se pudo guardar el lote'], 500);
        }

        return response()->json([
            'numero_lote' => $numeroLote,
            'compra_lote_id' => $compraLoteId,
            'fecha_ingreso' => $fecha,
            'producto_id' => $productoId,
            'producto_nombre' => $producto->nombre,
            'cantidad' => $cantidad,
            'presentacion_ingreso' => $presentacionIngreso,
            'cantidad_presentacion' => $cantidadPresentacion,
            'factor_conversion' => $factorConversion,
            'costo_kilo' => $costoKilo,
            'costo_total_compra' => $costoTotalCompra,
            'precio_venta' => $precioVenta,
            'codigo_comprobante' => $codigoComprobante,
            'creado_en' => now()->toDateTimeString(),
            'estado' => 'ABIERTO',
            'proveedor_id' => $proveedorId,
        ], 201);
    }

    public function lotesUpdate(Request $request, int $compraLoteId)
    {
        $this->asegurarColumnasHuevos();

        $validator = Validator::make($request->all(), [
            'producto_id' => ['required', 'integer', 'exists:productos,producto_id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
            'costo_kilo' => ['required', 'numeric', 'min:0'],
            'costo_total_compra' => ['nullable', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'presentacion_ingreso' => ['nullable', 'string', 'max:30'],
            'cantidad_presentacion' => ['nullable', 'numeric', 'min:0.01'],
            'codigo_comprobante' => ['required', 'string', 'max:50'],
            'fecha_ingreso' => ['required', 'date'],
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,proveedor_id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $lote = DB::table('compras_lote')->where('compra_lote_id', $compraLoteId)->first();
        if (!$lote) {
            return response()->json(['message' => 'Lote no encontrado'], 404);
        }

        if ($lote->estado === 'CERRADO') {
            return response()->json(['message' => 'El lote está cerrado y no se puede editar'], 409);
        }

        $productoId = (int) $request->input('producto_id');
        $cantidad = (float) $request->input('cantidad');
        $costoKilo = (float) $request->input('costo_kilo');
        $costoTotalCompra = $request->filled('costo_total_compra') ? (float) $request->input('costo_total_compra') : null;
        $precioVenta = (float) $request->input('precio_venta');
        $codigoComprobante = trim((string) $request->input('codigo_comprobante'));
        $fecha = $request->input('fecha_ingreso');
        $proveedorId = (int) $request->input('proveedor_id');

        $producto = DB::table('productos')->where('producto_id', $productoId)->first();
        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $esHuevo = ($producto->grupo_venta ?? '') === 'HUEVOS';
        $presentacionIngreso = $esHuevo ? strtoupper((string) $request->input('presentacion_ingreso', 'UNIDAD')) : null;
        $factorConversion = $esHuevo ? $this->factorPresentacionHuevo($presentacionIngreso) : null;
        if ($esHuevo && $factorConversion === null) {
            return response()->json(['message' => 'Presentacion de huevo no valida.'], 422);
        }
        $cantidadPresentacion = $esHuevo ? (float) $request->input('cantidad_presentacion', $cantidad) : null;
        if ($esHuevo) {
            $cantidad = round($cantidadPresentacion * $factorConversion, 2);
        }
        if ($costoTotalCompra === null) {
            $costoTotalCompra = round($cantidad * $costoKilo, 2);
        }

        DB::beginTransaction();
        try {
            DB::table('compras_lote')
                ->where('compra_lote_id', $compraLoteId)
                ->update([
                    'fecha_ingreso' => $fecha,
                    'codigo_comprobante' => $codigoComprobante,
                    'proveedor_id' => $proveedorId,
                ]);

            $detallePayload = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'costo_kilo' => $costoKilo,
                'precio_venta' => $precioVenta,
            ];

            if (Schema::hasColumn('compras_lote_detalle', 'presentacion_ingreso')) {
                $detallePayload['presentacion_ingreso'] = $presentacionIngreso;
            }
            if (Schema::hasColumn('compras_lote_detalle', 'cantidad_presentacion')) {
                $detallePayload['cantidad_presentacion'] = $cantidadPresentacion;
            }
            if (Schema::hasColumn('compras_lote_detalle', 'factor_conversion')) {
                $detallePayload['factor_conversion'] = $factorConversion;
            }
            if (Schema::hasColumn('compras_lote_detalle', 'costo_total_compra')) {
                $detallePayload['costo_total_compra'] = $costoTotalCompra;
            }

            $detalleActualizado = DB::table('compras_lote_detalle')
                ->where('compra_lote_id', $compraLoteId)
                ->update($detallePayload);

            if ($detalleActualizado === 0) {
                DB::table('compras_lote_detalle')->insert([
                    'compra_lote_id' => $compraLoteId,
                    ...$detallePayload,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'No se pudo actualizar el lote'], 500);
        }

        $numeroLote = (int) DB::table('compras_lote_detalle')
            ->where('producto_id', $productoId)
            ->count();

        return response()->json([
            'numero_lote' => $numeroLote,
            'compra_lote_id' => $compraLoteId,
            'fecha_ingreso' => $fecha,
            'producto_id' => $productoId,
            'producto_nombre' => $producto->nombre,
            'cantidad' => $cantidad,
            'presentacion_ingreso' => $presentacionIngreso,
            'cantidad_presentacion' => $cantidadPresentacion,
            'factor_conversion' => $factorConversion,
            'costo_kilo' => $costoKilo,
            'costo_total_compra' => $costoTotalCompra,
            'precio_venta' => $precioVenta,
            'codigo_comprobante' => $codigoComprobante,
            'creado_en' => $lote->creado_en,
            'estado' => $lote->estado,
            'proveedor_id' => $proveedorId,
        ]);
    }

    public function lotesDestroy(Request $request, int $compraLoteId)
    {
        $lote = DB::table('compras_lote')->where('compra_lote_id', $compraLoteId)->first();
        if (!$lote) {
            return response()->json(['message' => 'Lote no encontrado'], 404);
        }

        if ($lote->estado === 'CERRADO') {
            return response()->json(['message' => 'El lote está cerrado y no se puede eliminar'], 409);
        }

        DB::beginTransaction();
        try {
            DB::table('compras_lote_detalle')
                ->where('compra_lote_id', $compraLoteId)
                ->delete();

            DB::table('compras_lote')
                ->where('compra_lote_id', $compraLoteId)
                ->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'No se pudo eliminar el lote'], 500);
        }

        return response()->json(['message' => 'Lote eliminado']);
    }

    public function ventasDiariasEstado(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $validator = Validator::make($request->all(), [
            'fecha' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Fecha inválida', 'errors' => $validator->errors()], 422);
        }

        $usuario = $request->user();
        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $fecha = $request->query('fecha');
        $tienePedidoId = Schema::hasColumn('otros_productos_ventas_diarias', 'pedido_id');
        $tieneOrigen = Schema::hasColumn('otros_productos_ventas_diarias', 'origen');

        $columnasBase = [
            'opvd.venta_op_diaria_id',
            'opvd.producto_id',
            'opvd.compra_lote_detalle_id',
            'opvd.cantidad',
            Schema::hasColumn('otros_productos_ventas_diarias', 'presentacion_venta') ? 'opvd.presentacion_venta' : DB::raw('NULL as presentacion_venta'),
            Schema::hasColumn('otros_productos_ventas_diarias', 'cantidad_presentacion') ? 'opvd.cantidad_presentacion' : DB::raw('NULL as cantidad_presentacion'),
            Schema::hasColumn('otros_productos_ventas_diarias', 'factor_conversion') ? 'opvd.factor_conversion' : DB::raw('NULL as factor_conversion'),
            'opvd.precio',
            'opvd.total',
            'opvd.total_huevos',
            'opvd.total_congelados',
            'opvd.fecha_hora',
            'opvd.cerrado_en',
            'p.nombre as producto_nombre',
            'p.grupo_venta',
        ];

        $columnasConOrigen = [
            ...$columnasBase,
            $tienePedidoId ? 'opvd.pedido_id' : DB::raw('NULL as pedido_id'),
            $tieneOrigen ? 'opvd.origen' : DB::raw('NULL as origen'),
        ];

        $filasQuery = DB::table('otros_productos_ventas_diarias as opvd')
            ->join('productos as p', 'p.producto_id', '=', 'opvd.producto_id')
            ->select($columnasConOrigen)
            ->where('opvd.usuario_id', $usuario->usuario_id)
            ->whereDate('opvd.fecha_hora', $fecha);

        if ($tienePedidoId && $tieneOrigen) {
            $filasQuery
                ->leftJoin('pedidos as pd', 'pd.pedido_id', '=', 'opvd.pedido_id')
                ->where(function ($query) {
                    $query
                        ->whereNull('opvd.pedido_id')
                        ->orWhereNull('opvd.origen')
                        ->orWhere('opvd.origen', '!=', 'PEDIDO_DELIVERY')
                        ->orWhereNull('pd.estado_id')
                        ->orWhere('pd.estado_id', '!=', 3);
                });
        }

        $filas = $filasQuery
            ->orderBy('opvd.venta_op_diaria_id')
            ->get();

        $cierresHistoricos = DB::table('otros_productos_ventas_diarias as opvd')
            ->join('productos as p', 'p.producto_id', '=', 'opvd.producto_id')
            ->select([
                'opvd.venta_op_diaria_id',
                'opvd.producto_id',
                'opvd.cantidad',
                Schema::hasColumn('otros_productos_ventas_diarias', 'presentacion_venta') ? 'opvd.presentacion_venta' : DB::raw('NULL as presentacion_venta'),
                Schema::hasColumn('otros_productos_ventas_diarias', 'cantidad_presentacion') ? 'opvd.cantidad_presentacion' : DB::raw('NULL as cantidad_presentacion'),
                Schema::hasColumn('otros_productos_ventas_diarias', 'factor_conversion') ? 'opvd.factor_conversion' : DB::raw('NULL as factor_conversion'),
                'opvd.precio',
                'opvd.total',
                'opvd.total_huevos',
                'opvd.total_congelados',
                'opvd.fecha_hora',
                'opvd.cerrado_en',
                'p.nombre as producto_nombre',
                'p.grupo_venta',
            ])
            ->where('opvd.usuario_id', $usuario->usuario_id)
            ->whereNotNull('opvd.cerrado_en')
            ->whereNotNull('opvd.fecha_hora')
            ->orderByDesc('opvd.cerrado_en')
            ->orderByDesc('opvd.venta_op_diaria_id')
            ->get()
            ->groupBy(function ($item) {
                $fechaHora = (string) ($item->fecha_hora ?? '');
                return strlen($fechaHora) >= 10 ? substr($fechaHora, 0, 10) : now()->toDateString();
            })
            ->map(function ($rows, $fechaCierre) {
                $totales = [
                    'huevos' => 0,
                    'congelados' => 0,
                    'general' => 0,
                ];

                $items = [];

                foreach ($rows as $row) {
                    $subtotal = (float) $row->total;
                    $totales['general'] += $subtotal;

                    if ($row->grupo_venta === 'HUEVOS') {
                        $totales['huevos'] += $subtotal;
                    }

                    if ($row->grupo_venta === 'CONGELADO') {
                        $totales['congelados'] += $subtotal;
                    }

                    $items[] = [
                        'venta_op_diaria_id' => $row->venta_op_diaria_id,
                        'fecha_hora' => $row->fecha_hora,
                        'producto_id' => $row->producto_id,
                        'producto_nombre' => $row->producto_nombre,
                        'grupo_venta' => $row->grupo_venta,
                        'cantidad' => (float) $row->cantidad,
                        'presentacion_venta' => $row->presentacion_venta,
                        'cantidad_presentacion' => $row->cantidad_presentacion !== null ? (float) $row->cantidad_presentacion : null,
                        'factor_conversion' => $row->factor_conversion !== null ? (float) $row->factor_conversion : null,
                        'precio' => (float) $row->precio,
                        'total' => $subtotal,
                    ];
                }

                return [
                    'fecha' => $fechaCierre,
                    'cerrado_en' => optional(collect($rows)->first())->cerrado_en,
                    'total_huevos' => $totales['huevos'],
                    'total_congelados' => $totales['congelados'],
                    'total_general' => $totales['general'],
                    'items' => $items,
                ];
            })
            ->values();

        return response()->json([
            'fecha' => $fecha,
            'cerrado' => $filas->contains(fn ($item) => !is_null($item->cerrado_en)),
            'filas' => $filas,
            'cierres' => $cierresHistoricos,
        ]);
    }

    public function ventasDiariasGuardar(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $validator = Validator::make($request->all(), [
            'fecha' => ['required', 'date'],
            'filas' => ['array'],
            'filas.*.producto_id' => ['required', 'integer', 'exists:productos,producto_id'],
            'filas.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'filas.*.precio' => ['required', 'numeric', 'min:0'],
            'filas.*.presentacion_venta' => ['nullable', 'string', 'max:30'],
            'filas.*.cantidad_presentacion' => ['nullable', 'numeric', 'min:0.01'],
            'filas.*.fecha_hora' => ['nullable', 'date'],
            'filas.*.pedido_id' => ['nullable', 'integer'],
            'filas.*.origen' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $usuario = $request->user();
        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $fecha = $request->input('fecha');
        $filas = collect($request->input('filas', []));
        $tienePedidoId = Schema::hasColumn('otros_productos_ventas_diarias', 'pedido_id');
        $tieneOrigen = Schema::hasColumn('otros_productos_ventas_diarias', 'origen');

        $hayCierre = DB::table('otros_productos_ventas_diarias')
            ->where('usuario_id', $usuario->usuario_id)
            ->whereDate('fecha_hora', $fecha)
            ->whereNotNull('cerrado_en')
            ->exists();

        if ($hayCierre) {
            return response()->json(['message' => 'El día ya está cerrado. Reabre para editar.'], 409);
        }

        $productosVenta = DB::table('productos')
            ->whereIn('producto_id', $filas->pluck('producto_id')->filter()->values())
            ->get()
            ->keyBy('producto_id');

        try {
            $filasNormalizadas = $filas->map(function ($fila) use ($productosVenta) {
                $producto = $productosVenta[(int) $fila['producto_id']] ?? null;
                return $this->normalizarFilaVentaDiaria($fila, $producto);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $totales = $this->calcularTotalesPorFilas($filasNormalizadas);

        DB::beginTransaction();
        try {
            DB::table('otros_productos_ventas_diarias')
                ->where('usuario_id', $usuario->usuario_id)
                ->whereDate('fecha_hora', $fecha)
                ->whereNull('cerrado_en')
                ->delete();

            foreach ($filasNormalizadas as $fila) {
                $fechaHoraFila = isset($fila['fecha_hora']) && !empty($fila['fecha_hora'])
                    ? Carbon::parse($fila['fecha_hora'])->format('Y-m-d H:i:s')
                    : Carbon::parse($fecha)->format('Y-m-d H:i:s');

                $datosFila = [
                    'usuario_id' => $usuario->usuario_id,
                    'producto_id' => (int) $fila['producto_id'],
                    'compra_lote_detalle_id' => null,
                    'cantidad' => (float) $fila['cantidad'],
                    'precio' => (float) $fila['precio'],
                    'fecha_hora' => $fechaHoraFila,
                    'total' => (float) $fila['total'],
                    'total_huevos' => $totales['huevos'],
                    'total_congelados' => $totales['congelados'],
                    'cerrado_en' => null,
                    'creado_en' => now(),
                ];

                if ($tienePedidoId) {
                    $datosFila['pedido_id'] = isset($fila['pedido_id']) ? (int) $fila['pedido_id'] : null;
                }

                if ($tieneOrigen) {
                    $datosFila['origen'] = isset($fila['origen']) ? trim((string) $fila['origen']) : null;
                }

                if (Schema::hasColumn('otros_productos_ventas_diarias', 'presentacion_venta')) {
                    $datosFila['presentacion_venta'] = $fila['presentacion_venta'] ?? null;
                }
                if (Schema::hasColumn('otros_productos_ventas_diarias', 'cantidad_presentacion')) {
                    $datosFila['cantidad_presentacion'] = $fila['cantidad_presentacion'] ?? null;
                }
                if (Schema::hasColumn('otros_productos_ventas_diarias', 'factor_conversion')) {
                    $datosFila['factor_conversion'] = $fila['factor_conversion'] ?? null;
                }

                DB::table('otros_productos_ventas_diarias')->insert($datosFila);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'No se pudo guardar el borrador'], 500);
        }

        return response()->json(['message' => 'Borrador guardado']);
    }

    public function ventasDiariasCerrar(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $validator = Validator::make($request->all(), [
            'fecha' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Fecha inválida', 'errors' => $validator->errors()], 422);
        }

        $usuario = $request->user();
        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $fecha = $request->input('fecha');

        $filas = DB::table('otros_productos_ventas_diarias')
            ->where('usuario_id', $usuario->usuario_id)
            ->whereDate('fecha_hora', $fecha)
            ->whereNull('cerrado_en')
            ->orderBy('venta_op_diaria_id')
            ->get();

        if ($filas->isEmpty()) {
            $yaCerrado = DB::table('otros_productos_ventas_diarias')
                ->where('usuario_id', $usuario->usuario_id)
                ->whereDate('fecha_hora', $fecha)
                ->whereNotNull('cerrado_en')
                ->exists();

            if ($yaCerrado) {
                return response()->json(['message' => 'El día ya está cerrado. Debes reabrir el día para editar.'], 409);
            }

            return response()->json(['message' => 'No hay filas para cerrar en esta fecha'], 422);
        }

        $ahora = Carbon::now('America/Lima')->format('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($filas as $fila) {
                $consumos = $this->consumirStockPorLotes(
                    (int) $fila->producto_id,
                    (float) $fila->cantidad,
                    (int) $fila->venta_op_diaria_id
                );
                $primerLoteId = $consumos[0]['compra_lote_detalle_id'] ?? null;
/*
                $detalleLote = DB::table('compras_lote_detalle as cld')
                    ->join('compras_lote as cl', 'cl.compra_lote_id', '=', 'cld.compra_lote_id')
                    ->where('cld.producto_id', $fila->producto_id)
                    ->where('cld.cantidad', '>=', $fila->cantidad)
                    ->where('cl.estado', 'ABIERTO')
                    ->orderBy('cl.fecha_ingreso')
                    ->orderBy('cld.compra_lote_detalle_id')
                    ->lockForUpdate()
                    ->first(['cld.compra_lote_detalle_id', 'cld.cantidad']);

                if (!$detalleLote) {
                    throw new \RuntimeException('Stock insuficiente para cerrar el día.');
                }

                DB::table('compras_lote_detalle')
                    ->where('compra_lote_detalle_id', $detalleLote->compra_lote_detalle_id)
                    ->update([
                        'cantidad' => (float) $detalleLote->cantidad - (float) $fila->cantidad,
                    ]);
*/

                DB::table('otros_productos_ventas_diarias')
                    ->where('venta_op_diaria_id', $fila->venta_op_diaria_id)
                    ->update([
                        'compra_lote_detalle_id' => $primerLoteId,
                        'cerrado_en' => $ahora,
                    ]);
            }

            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'No se pudo cerrar el día'], 500);
        }

        return response()->json(['message' => 'Día cerrado correctamente']);
    }

    public function ventasDiariasReabrir(Request $request)
    {
        $this->asegurarColumnasHuevos();

        $validator = Validator::make($request->all(), [
            'fecha' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Fecha inválida', 'errors' => $validator->errors()], 422);
        }

        $usuario = $request->user();
        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $fecha = $request->input('fecha');

        $filas = DB::table('otros_productos_ventas_diarias')
            ->where('usuario_id', $usuario->usuario_id)
            ->whereDate('fecha_hora', $fecha)
            ->whereNotNull('cerrado_en')
            ->get();

        if ($filas->isEmpty()) {
            return response()->json(['message' => 'No hay cierre para reabrir en esta fecha'], 422);
        }

        DB::beginTransaction();
        try {
            $this->restaurarStockPorConsumos($filas);

            DB::table('otros_productos_ventas_diarias')
                ->where('usuario_id', $usuario->usuario_id)
                ->whereDate('fecha_hora', $fecha)
                ->update([
                    'cerrado_en' => null,
                    'compra_lote_detalle_id' => null,
                ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'No se pudo reabrir el día'], 500);
        }

        return response()->json(['message' => 'Día reabierto correctamente']);
    }

    private function consumirStockPorLotes(int $productoId, float $cantidadNecesaria, int $ventaId): array
    {
        $restante = round($cantidadNecesaria, 4);
        $consumos = [];

        DB::table('otros_productos_venta_lotes_consumos')
            ->where('venta_op_diaria_id', $ventaId)
            ->delete();

        $lotes = DB::table('compras_lote_detalle as cld')
            ->join('compras_lote as cl', 'cl.compra_lote_id', '=', 'cld.compra_lote_id')
            ->join('productos as p', 'p.producto_id', '=', 'cld.producto_id')
            ->where('cld.producto_id', $productoId)
            ->where('cld.cantidad', '>', 0)
            ->where('cl.estado', 'ABIERTO')
            ->orderBy('cl.fecha_ingreso')
            ->orderBy('cld.compra_lote_detalle_id')
            ->lockForUpdate()
            ->get([
                'cld.compra_lote_detalle_id',
                'cld.cantidad',
                'cld.costo_kilo',
                Schema::hasColumn('compras_lote_detalle', 'costo_total_compra') ? 'cld.costo_total_compra' : DB::raw('NULL as costo_total_compra'),
                Schema::hasColumn('compras_lote_detalle', 'cantidad_presentacion') ? 'cld.cantidad_presentacion' : DB::raw('NULL as cantidad_presentacion'),
                Schema::hasColumn('compras_lote_detalle', 'factor_conversion') ? 'cld.factor_conversion' : DB::raw('NULL as factor_conversion'),
                'p.grupo_venta',
            ]);

        foreach ($lotes as $lote) {
            if ($restante <= 0) {
                break;
            }

            $stockDisponible = (float) $lote->cantidad;
            $cantidadConsumida = min($restante, $stockDisponible);
            $costoUnitario = $this->costoUnitarioLote($lote);
            $costoTotal = round($cantidadConsumida * $costoUnitario, 2);

            DB::table('compras_lote_detalle')
                ->where('compra_lote_detalle_id', $lote->compra_lote_detalle_id)
                ->update([
                    'cantidad' => round($stockDisponible - $cantidadConsumida, 4),
                ]);

            DB::table('otros_productos_venta_lotes_consumos')->insert([
                'venta_op_diaria_id' => $ventaId,
                'compra_lote_detalle_id' => $lote->compra_lote_detalle_id,
                'cantidad' => $cantidadConsumida,
                'costo_unitario' => $costoUnitario,
                'costo_total' => $costoTotal,
                'creado_en' => now(),
            ]);

            $consumos[] = [
                'compra_lote_detalle_id' => $lote->compra_lote_detalle_id,
                'cantidad' => $cantidadConsumida,
                'costo_total' => $costoTotal,
            ];

            $restante = round($restante - $cantidadConsumida, 4);
        }

        if ($restante > 0.0001) {
            throw new \RuntimeException('Stock insuficiente para cerrar el dÃ­a.');
        }

        return $consumos;
    }

    private function restaurarStockPorConsumos($filas): void
    {
        $ventaIds = collect($filas)->pluck('venta_op_diaria_id')->filter()->values();
        $consumos = DB::table('otros_productos_venta_lotes_consumos')
            ->whereIn('venta_op_diaria_id', $ventaIds)
            ->lockForUpdate()
            ->get();

        if ($consumos->isNotEmpty()) {
            foreach ($consumos as $consumo) {
                $detalle = DB::table('compras_lote_detalle')
                    ->where('compra_lote_detalle_id', $consumo->compra_lote_detalle_id)
                    ->lockForUpdate()
                    ->first();

                if ($detalle) {
                    DB::table('compras_lote_detalle')
                        ->where('compra_lote_detalle_id', $consumo->compra_lote_detalle_id)
                        ->update([
                            'cantidad' => round((float) $detalle->cantidad + (float) $consumo->cantidad, 4),
                        ]);
                }
            }

            DB::table('otros_productos_venta_lotes_consumos')
                ->whereIn('venta_op_diaria_id', $ventaIds)
                ->delete();

            return;
        }

        foreach ($filas as $fila) {
            if ($fila->compra_lote_detalle_id) {
                $detalle = DB::table('compras_lote_detalle')
                    ->where('compra_lote_detalle_id', $fila->compra_lote_detalle_id)
                    ->lockForUpdate()
                    ->first();

                if ($detalle) {
                    DB::table('compras_lote_detalle')
                        ->where('compra_lote_detalle_id', $fila->compra_lote_detalle_id)
                        ->update([
                            'cantidad' => round((float) $detalle->cantidad + (float) $fila->cantidad, 4),
                        ]);
                }
            }
        }
    }

    private function costoUnitarioLote(object $lote): float
    {
        if (($lote->grupo_venta ?? '') === 'HUEVOS') {
            $cantidadOriginal = (float) ($lote->cantidad_presentacion ?? 0) * (float) ($lote->factor_conversion ?? 0);
            $costoTotal = (float) ($lote->costo_total_compra ?? 0);

            if ($cantidadOriginal > 0 && $costoTotal > 0) {
                return round($costoTotal / $cantidadOriginal, 6);
            }
        }

        return round((float) ($lote->costo_kilo ?? 0), 6);
    }

    private function calcularTotalesPorFilas($filas): array
    {
        $productoIds = collect($filas)->pluck('producto_id')->filter()->values();
        if ($productoIds->isEmpty()) {
            return ['huevos' => 0, 'congelados' => 0];
        }

        $productos = DB::table('productos')
            ->whereIn('producto_id', $productoIds)
            ->pluck('grupo_venta', 'producto_id');

        $huevos = 0;
        $congelados = 0;

        foreach ($filas as $fila) {
            $productoId = (int) ($fila['producto_id'] ?? 0);
            $subtotal = isset($fila['total'])
                ? (float) $fila['total']
                : (float) ($fila['cantidad'] ?? 0) * (float) ($fila['precio'] ?? 0);
            $grupo = $productos[$productoId] ?? 'OTROS';

            if ($grupo === 'HUEVOS') {
                $huevos += $subtotal;
            }

            if ($grupo === 'CONGELADO') {
                $congelados += $subtotal;
            }
        }

        return [
            'huevos' => $huevos,
            'congelados' => $congelados,
        ];
    }

    private function normalizarFilaVentaDiaria($fila, ?object $producto): array
    {
        $productoId = (int) ($fila['producto_id'] ?? 0);
        $grupo = $producto?->grupo_venta ?? 'OTROS';
        $cantidad = (float) ($fila['cantidad'] ?? 0);
        $precio = (float) ($fila['precio'] ?? 0);

        $normalizada = [
            ...$fila,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'precio' => $precio,
            'total' => round($cantidad * $precio, 2),
            'presentacion_venta' => null,
            'cantidad_presentacion' => null,
            'factor_conversion' => null,
        ];

        if ($grupo !== 'HUEVOS' || empty($fila['presentacion_venta'])) {
            return $normalizada;
        }

        $presentacion = strtoupper((string) ($fila['presentacion_venta'] ?? 'UNIDAD'));
        $factor = $this->factorPresentacionHuevo($presentacion);
        if ($factor === null) {
            throw new \InvalidArgumentException('Presentacion de huevo no valida.');
        }

        $cantidadPresentacion = (float) ($fila['cantidad_presentacion'] ?? $cantidad);
        $cantidadBase = round($cantidadPresentacion * $factor, 2);

        return [
            ...$normalizada,
            'cantidad' => $cantidadBase,
            'precio' => $precio,
            'total' => round($precio, 2),
            'presentacion_venta' => $presentacion,
            'cantidad_presentacion' => $cantidadPresentacion,
            'factor_conversion' => $factor,
        ];
    }

    private function factorPresentacionHuevo(?string $presentacion): ?float
    {
        $clave = strtoupper((string) $presentacion);
        return isset(self::PRESENTACIONES_HUEVO[$clave]) ? (float) self::PRESENTACIONES_HUEVO[$clave] : null;
    }

    private function asegurarColumnasHuevos(): void
    {
        if (!Schema::hasTable('otros_productos_venta_lotes_consumos')) {
            Schema::create('otros_productos_venta_lotes_consumos', function (Blueprint $table) {
                $table->id('consumo_id');
                $table->unsignedBigInteger('venta_op_diaria_id');
                $table->unsignedBigInteger('compra_lote_detalle_id');
                $table->decimal('cantidad', 12, 4);
                $table->decimal('costo_unitario', 12, 6);
                $table->decimal('costo_total', 12, 2);
                $table->timestamp('creado_en')->nullable();
                $table->index('venta_op_diaria_id', 'op_consumos_venta_idx');
                $table->index('compra_lote_detalle_id', 'op_consumos_lote_idx');
            });
        }

        if (Schema::hasTable('compras_lote_detalle')) {
            $columnas = [
                'presentacion_ingreso' => fn (Blueprint $table) => $table->string('presentacion_ingreso', 30)->nullable()->after('cantidad'),
                'cantidad_presentacion' => fn (Blueprint $table) => $table->decimal('cantidad_presentacion', 12, 2)->nullable()->after('presentacion_ingreso'),
                'factor_conversion' => fn (Blueprint $table) => $table->decimal('factor_conversion', 12, 2)->nullable()->after('cantidad_presentacion'),
                'costo_total_compra' => fn (Blueprint $table) => $table->decimal('costo_total_compra', 12, 2)->nullable()->after('costo_kilo'),
            ];

            foreach ($columnas as $columna => $callback) {
                if (!Schema::hasColumn('compras_lote_detalle', $columna)) {
                    Schema::table('compras_lote_detalle', $callback);
                }
            }
        }

        if (Schema::hasTable('otros_productos_ventas_diarias')) {
            $columnas = [
                'presentacion_venta' => fn (Blueprint $table) => $table->string('presentacion_venta', 30)->nullable()->after('cantidad'),
                'cantidad_presentacion' => fn (Blueprint $table) => $table->decimal('cantidad_presentacion', 12, 2)->nullable()->after('presentacion_venta'),
                'factor_conversion' => fn (Blueprint $table) => $table->decimal('factor_conversion', 12, 2)->nullable()->after('cantidad_presentacion'),
            ];

            foreach ($columnas as $columna => $callback) {
                if (!Schema::hasColumn('otros_productos_ventas_diarias', $columna)) {
                    Schema::table('otros_productos_ventas_diarias', $callback);
                }
            }
        }
    }

}
