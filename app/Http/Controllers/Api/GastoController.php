<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GastoController extends Controller
{
    private const FONDO_POLLO = 'POLLO_GALLINA';
    private const FONDO_OTROS = 'CONGELADOS_HUEVOS';

    public function resumen(Request $request)
    {
        [$desde, $hasta] = $this->resolverRango($request);
        $fondo = $request->query('fondo');

        return response()->json([
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'fondos' => $this->armarFondos($desde, $hasta, $fondo),
            'movimientos' => $this->movimientos($desde, $hasta, $fondo),
            'auditoria' => $this->auditoria($desde, $hasta, $fondo),
            'categorias' => $this->categoriasBase(),
            'cierres_mensuales' => $this->cierresMensuales(),
            'venta_pollo_gallina' => $this->ventaPolloGallina($request->query('fecha', Carbon::now('America/Lima')->toDateString())),
        ]);
    }

    public function categorias()
    {
        return response()->json($this->categoriasBase());
    }

    public function guardarCategoria(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:60'],
        ]);

        $nombre = trim($validated['nombre']);
        $antes = DB::table('gasto_categorias')->where('nombre', $nombre)->first();
        $id = DB::table('gasto_categorias')->updateOrInsert(
            ['nombre' => $nombre],
            ['nombre' => $nombre]
        );
        $categoria = DB::table('gasto_categorias')->where('nombre', $nombre)->first();

        $this->registrarAuditoria(
            $request,
            $antes ? 'CATEGORIA_EXISTENTE' : 'CATEGORIA_CREADA',
            'gasto_categorias',
            $categoria->categoria_id ?? null,
            null,
            'Categoria de gasto: ' . $nombre,
            $antes,
            $categoria
        );

        return response()->json([
            'message' => 'Categoria guardada correctamente.',
            'categoria' => $categoria,
            'created' => $id,
        ], 201);
    }

    public function guardarGasto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fondo' => ['required', Rule::in([self::FONDO_POLLO, self::FONDO_OTROS])],
            'categoria_id' => ['nullable', 'integer', 'exists:gasto_categorias,categoria_id'],
            'categoria_nombre' => ['nullable', 'string', 'max:60'],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:200'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'nota' => ['nullable', 'string', 'max:250'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos invalidos', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $fechaGasto = Carbon::parse($validated['fecha'])->toDateString();
        if ($this->periodoEstaCerrado($fechaGasto)) {
            return response()->json(['message' => 'Este mes ya esta cerrado. No se pueden registrar gastos en ese periodo.'], 409);
        }

        $categoriaId = $validated['categoria_id'] ?? null;

        if (!$categoriaId && !empty($validated['categoria_nombre'])) {
            $nombre = trim((string) $validated['categoria_nombre']);
            DB::table('gasto_categorias')->updateOrInsert(['nombre' => $nombre], ['nombre' => $nombre]);
            $categoriaId = DB::table('gasto_categorias')->where('nombre', $nombre)->value('categoria_id');
        }

        $gastoId = DB::table('gastos')->insertGetId([
            'usuario_id' => $request->user()->usuario_id,
            'categoria_id' => $categoriaId,
            'fondo' => $validated['fondo'],
            'fecha' => $fechaGasto,
            'descripcion' => trim($validated['descripcion']),
            'monto' => (float) $validated['monto'],
            'nota' => isset($validated['nota']) ? trim((string) $validated['nota']) : null,
            'estado' => 'ACTIVO',
            'creado_en' => now(),
        ]);

        $gasto = DB::table('gastos')->where('gasto_id', $gastoId)->first();
        $this->registrarAuditoria(
            $request,
            'GASTO_REGISTRADO',
            'gastos',
            $gastoId,
            $validated['fondo'],
            'Gasto registrado: ' . trim($validated['descripcion']),
            null,
            $gasto
        );

        return response()->json([
            'message' => 'Gasto registrado correctamente.',
            'gasto' => $gasto,
        ], 201);
    }

    public function eliminarGasto(Request $request, int $gastoId)
    {
        $gasto = DB::table('gastos')->where('gasto_id', $gastoId)->first();

        if (!$gasto) {
            return response()->json(['message' => 'Gasto no encontrado.'], 404);
        }

        if (($gasto->estado ?? 'ACTIVO') === 'ANULADO') {
            return response()->json(['message' => 'Este gasto ya esta anulado.'], 409);
        }

        if ($this->periodoEstaCerrado($gasto->fecha)) {
            return response()->json(['message' => 'Este mes ya esta cerrado. No se pueden anular gastos de ese periodo.'], 409);
        }

        $motivo = trim((string) $request->input('motivo', 'Anulacion sin detalle'));
        DB::table('gastos')
            ->where('gasto_id', $gastoId)
            ->update([
                'estado' => 'ANULADO',
                'anulado_en' => now(),
                'anulado_por' => $request->user()->usuario_id,
                'motivo_anulacion' => $motivo,
            ]);

        $gastoAnulado = DB::table('gastos')->where('gasto_id', $gastoId)->first();
        $this->registrarAuditoria(
            $request,
            'GASTO_ANULADO',
            'gastos',
            $gastoId,
            $gasto->fondo ?? null,
            'Gasto anulado: ' . ($gasto->descripcion ?? ''),
            $gasto,
            $gastoAnulado
        );

        return response()->json(['message' => 'Gasto anulado correctamente.']);
    }

    public function guardarCapital(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fondo' => ['required', Rule::in([self::FONDO_POLLO, self::FONDO_OTROS])],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:200'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'nota' => ['nullable', 'string', 'max:250'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos invalidos', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $fechaCapital = Carbon::parse($validated['fecha'])->toDateString();
        if ($this->periodoEstaCerrado($fechaCapital)) {
            return response()->json(['message' => 'Este mes ya esta cerrado. No se puede agregar capital en ese periodo.'], 409);
        }

        $capitalId = DB::table('gasto_capitales')->insertGetId([
            'usuario_id' => $request->user()->usuario_id,
            'fondo' => $validated['fondo'],
            'fecha' => $fechaCapital,
            'monto' => (float) $validated['monto'],
            'descripcion' => trim($validated['descripcion']),
            'nota' => isset($validated['nota']) ? trim((string) $validated['nota']) : null,
            'estado' => 'ACTIVO',
            'creado_en' => now(),
        ]);

        $capital = DB::table('gasto_capitales')->where('capital_id', $capitalId)->first();
        $this->registrarAuditoria(
            $request,
            'CAPITAL_AGREGADO',
            'gasto_capitales',
            $capitalId,
            $validated['fondo'],
            'Capital agregado: ' . trim($validated['descripcion']),
            null,
            $capital
        );

        return response()->json([
            'message' => 'Capital agregado correctamente.',
            'capital' => $capital,
        ], 201);
    }

    public function anularCapital(Request $request, int $capitalId)
    {
        $capital = DB::table('gasto_capitales')->where('capital_id', $capitalId)->first();

        if (!$capital) {
            return response()->json(['message' => 'Capital no encontrado.'], 404);
        }

        if (($capital->estado ?? 'ACTIVO') === 'ANULADO') {
            return response()->json(['message' => 'Este capital ya esta anulado.'], 409);
        }

        if ($this->periodoEstaCerrado($capital->fecha)) {
            return response()->json(['message' => 'Este mes ya esta cerrado. No se puede anular capital de ese periodo.'], 409);
        }

        $motivo = trim((string) $request->input('motivo', 'Anulacion sin detalle'));
        DB::table('gasto_capitales')
            ->where('capital_id', $capitalId)
            ->update([
                'estado' => 'ANULADO',
                'anulado_en' => now(),
                'anulado_por' => $request->user()->usuario_id,
                'motivo_anulacion' => $motivo,
            ]);

        $capitalAnulado = DB::table('gasto_capitales')->where('capital_id', $capitalId)->first();
        $this->registrarAuditoria(
            $request,
            'CAPITAL_ANULADO',
            'gasto_capitales',
            $capitalId,
            $capital->fondo ?? null,
            'Capital anulado: ' . ($capital->descripcion ?? ''),
            $capital,
            $capitalAnulado
        );

        return response()->json(['message' => 'Capital anulado correctamente.']);
    }

    public function guardarVentaPolloGallina(Request $request)
    {
        $validated = $request->validate([
            'fecha' => ['required', 'date'],
            'venta_pollo' => ['nullable', 'numeric', 'min:0'],
            'venta_gallina' => ['nullable', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:250'],
        ]);

        $usuarioId = $request->user()->usuario_id;
        $fecha = Carbon::parse($validated['fecha'])->toDateString();
        if ($this->periodoEstaCerrado($fecha)) {
            return response()->json(['message' => 'Este mes ya esta cerrado. No se pueden agregar ventas en ese periodo.'], 409);
        }

        $antes = DB::table('ventas_pollo_gallina_diarias')
            ->where('usuario_id', $usuarioId)
            ->where('fecha', $fecha)
            ->first();

        $montoPollo = (float) ($validated['venta_pollo'] ?? 0);
        $montoGallina = (float) ($validated['venta_gallina'] ?? 0);
        $observacion = isset($validated['observacion']) ? trim((string) $validated['observacion']) : null;

        if ($antes) {
            DB::table('ventas_pollo_gallina_diarias')
                ->where('venta_pg_id', $antes->venta_pg_id)
                ->update([
                    'venta_pollo' => (float) $antes->venta_pollo + $montoPollo,
                    'venta_gallina' => (float) $antes->venta_gallina + $montoGallina,
                    'observacion' => $this->unirObservaciones($antes->observacion ?? null, $observacion),
                    'actualizado_en' => now(),
                ]);
        } else {
            DB::table('ventas_pollo_gallina_diarias')->insert([
                'usuario_id' => $usuarioId,
                'fecha' => $fecha,
                'venta_pollo' => $montoPollo,
                'venta_gallina' => $montoGallina,
                'observacion' => $observacion,
                'creado_en' => now(),
                'actualizado_en' => now(),
            ]);
        }

        $venta = $this->ventaPolloGallina($fecha, $usuarioId);
        $this->registrarAuditoria(
            $request,
            $antes ? 'VENTA_POLLO_GALLINA_SUMADA' : 'VENTA_POLLO_GALLINA_REGISTRADA',
            'ventas_pollo_gallina_diarias',
            $venta->venta_pg_id ?? null,
            self::FONDO_POLLO,
            'Venta agregada pollo/gallina del ' . $fecha,
            $antes,
            $venta
        );

        return response()->json([
            'message' => 'Venta de pollo y gallina sumada correctamente.',
            'venta' => $venta,
        ]);
    }

    public function cerrarMes(Request $request)
    {
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Y-m'],
            'observacion' => ['nullable', 'string', 'max:250'],
        ]);

        $inicio = Carbon::createFromFormat('Y-m-d', $validated['periodo'] . '-01');
        $desde = $inicio->copy()->startOfMonth()->toDateString();
        $hasta = $inicio->copy()->endOfMonth()->toDateString();
        if (DB::table('gasto_cierres_mensuales')->where('periodo', $validated['periodo'])->exists()) {
            return response()->json(['message' => 'Este mes ya fue cerrado.'], 409);
        }

        $fondos = $this->armarFondos($desde, $hasta, null);
        $pollo = $fondos[self::FONDO_POLLO];
        $otros = $fondos[self::FONDO_OTROS];

        $payload = [
            'usuario_id' => $request->user()->usuario_id,
            'periodo' => $validated['periodo'],
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'pollo_ventas' => $pollo['ventas'],
            'pollo_costos' => $pollo['costos'],
            'pollo_ganancia' => $pollo['ganancia'],
            'pollo_gastos' => $pollo['gastos'],
            'pollo_saldo' => $pollo['saldo_periodo'],
            'otros_ventas' => $otros['ventas'],
            'otros_costos' => $otros['costos'],
            'otros_ganancia' => $otros['ganancia'],
            'otros_gastos' => $otros['gastos'],
            'otros_saldo' => $otros['saldo_periodo'],
            'observacion' => isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
            'cerrado_en' => now(),
        ];

        $antes = null;
        DB::table('gasto_cierres_mensuales')->insert($payload);
        $cierre = DB::table('gasto_cierres_mensuales')->where('periodo', $validated['periodo'])->first();

        $this->registrarAuditoria(
            $request,
            $antes ? 'CIERRE_MENSUAL_ACTUALIZADO' : 'CIERRE_MENSUAL_REGISTRADO',
            'gasto_cierres_mensuales',
            $cierre->cierre_id ?? null,
            null,
            'Cierre mensual ' . $validated['periodo'],
            $antes,
            $cierre
        );

        return response()->json([
            'message' => 'Mes cerrado correctamente.',
            'cierre' => $cierre,
        ]);
    }

    private function armarFondos(string $desde, string $hasta, ?string $fondoFiltro): array
    {
        $fondos = [
            self::FONDO_POLLO => $this->calcularPolloGallina($desde, $hasta),
            self::FONDO_OTROS => $this->calcularCongeladosHuevos($desde, $hasta),
        ];

        foreach ($fondos as $codigo => $datos) {
            $gastos = $this->sumarGastos($codigo, $desde, $hasta);
            $capital = $this->sumarCapital($codigo, $desde, $hasta);
            $pagosCompras = $codigo === self::FONDO_POLLO
                ? (float) $datos['costos']
                : $this->sumarComprasOtrosProductos($desde, $hasta);
            $saldoTotal = $this->calcularSaldoTotal($codigo);
            $fondos[$codigo] = array_merge($datos, [
                'codigo' => $codigo,
                'nombre' => $codigo === self::FONDO_POLLO ? 'Pollo + Gallina' : 'Congelados + Huevos',
                'capital' => $capital,
                'pagos_compras' => round($pagosCompras, 2),
                'gastos' => $gastos,
                'saldo_periodo' => round($capital + $datos['ventas'] - $pagosCompras - $gastos, 2),
                'saldo_disponible' => $saldoTotal,
            ]);
        }

        if ($fondoFiltro && isset($fondos[$fondoFiltro])) {
            return [$fondoFiltro => $fondos[$fondoFiltro]];
        }

        return $fondos;
    }

    private function calcularPolloGallina(string $desde, string $hasta): array
    {
        $ventas = (float) DB::table('ventas_pollo_gallina_diarias')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(venta_pollo + venta_gallina), 0) as total')
            ->value('total');

        $compras = (float) DB::table('entregas_proveedor')
            ->whereBetween(DB::raw('DATE(fecha_hora)'), [$desde, $hasta])
            ->where(function ($query) {
                $query
                    ->whereRaw('UPPER(tipo) LIKE ?', ['%POLLO%'])
                    ->orWhereRaw('UPPER(tipo) LIKE ?', ['%GALLINA%']);
            })
            ->sum('costo_total');

        return [
            'ventas' => round($ventas, 2),
            'costos' => round($compras, 2),
            'ganancia' => round($ventas - $compras, 2),
        ];
    }

    private function calcularCongeladosHuevos(string $desde, string $hasta): array
    {
        $usaCostoTotalCompra = Schema::hasColumn('compras_lote_detalle', 'costo_total_compra');
        $usaConsumosPorLote = Schema::hasTable('otros_productos_venta_lotes_consumos');
        $formulaCosto = $usaCostoTotalCompra
            ? "
                CASE
                    WHEN p.grupo_venta = 'HUEVOS'
                        AND cld.cantidad_presentacion IS NOT NULL
                        AND cld.factor_conversion IS NOT NULL
                        AND (cld.cantidad_presentacion * cld.factor_conversion) > 0
                    THEN opvd.cantidad * (
                        COALESCE(cld.costo_total_compra, cld.costo_kilo * (cld.cantidad_presentacion * cld.factor_conversion))
                        / (cld.cantidad_presentacion * cld.factor_conversion)
                    )
                    ELSE opvd.cantidad * COALESCE(cld.costo_kilo, 0)
                END
            "
            : 'opvd.cantidad * COALESCE(cld.costo_kilo, 0)';

        $query = DB::table('otros_productos_ventas_diarias as opvd')
            ->join('productos as p', 'p.producto_id', '=', 'opvd.producto_id')
            ->leftJoin('compras_lote_detalle as cld', 'cld.compra_lote_detalle_id', '=', 'opvd.compra_lote_detalle_id')
            ->whereBetween(DB::raw('DATE(opvd.fecha_hora)'), [$desde, $hasta])
            ->whereIn('p.grupo_venta', ['HUEVOS', 'CONGELADO'])
            ->selectRaw('COALESCE(SUM(opvd.total), 0) as ventas');

        if ($usaConsumosPorLote) {
            $costosPorVenta = DB::table('otros_productos_venta_lotes_consumos')
                ->select('venta_op_diaria_id')
                ->selectRaw('SUM(costo_total) as costo_total')
                ->groupBy('venta_op_diaria_id');

            $query
                ->leftJoinSub($costosPorVenta, 'consumos', function ($join) {
                    $join->on('consumos.venta_op_diaria_id', '=', 'opvd.venta_op_diaria_id');
                })
                ->selectRaw("COALESCE(SUM(COALESCE(consumos.costo_total, $formulaCosto)), 0) as costos");
        } else {
            $query->selectRaw("COALESCE(SUM($formulaCosto), 0) as costos");
        }

        $row = $query->first();

        $ventas = (float) ($row->ventas ?? 0);
        $costos = (float) ($row->costos ?? 0);

        return [
            'ventas' => round($ventas, 2),
            'costos' => round($costos, 2),
            'ganancia' => round($ventas - $costos, 2),
        ];
    }

    private function calcularSaldoTotal(string $fondo): float
    {
        $desde = '2000-01-01';
        $hasta = '2999-12-31';
        $datos = $fondo === self::FONDO_POLLO
            ? $this->calcularPolloGallina($desde, $hasta)
            : $this->calcularCongeladosHuevos($desde, $hasta);
        $pagosCompras = $fondo === self::FONDO_POLLO
            ? (float) $datos['costos']
            : $this->sumarComprasOtrosProductos($desde, $hasta);

        return round($this->sumarCapital($fondo, $desde, $hasta) + $datos['ventas'] - $pagosCompras - $this->sumarGastos($fondo, $desde, $hasta), 2);
    }

    private function sumarGastos(string $fondo, string $desde, string $hasta): float
    {
        return round((float) DB::table('gastos')
            ->where('fondo', $fondo)
            ->where('estado', 'ACTIVO')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('monto'), 2);
    }

    private function sumarCapital(string $fondo, string $desde, string $hasta): float
    {
        if (!Schema::hasTable('gasto_capitales')) {
            return 0;
        }

        return round((float) DB::table('gasto_capitales')
            ->where('fondo', $fondo)
            ->where('estado', 'ACTIVO')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('monto'), 2);
    }

    private function sumarComprasOtrosProductos(string $desde, string $hasta): float
    {
        if (!Schema::hasTable('compras_lote') || !Schema::hasTable('compras_lote_detalle')) {
            return 0;
        }

        $usaCostoTotalCompra = Schema::hasColumn('compras_lote_detalle', 'costo_total_compra');
        $formula = $usaCostoTotalCompra
            ? 'COALESCE(cld.costo_total_compra, cld.costo_kilo * cld.cantidad)'
            : 'cld.costo_kilo * cld.cantidad';

        return round((float) DB::table('compras_lote as cl')
            ->join('compras_lote_detalle as cld', 'cl.compra_lote_id', '=', 'cld.compra_lote_id')
            ->whereBetween('cl.fecha_ingreso', [$desde, $hasta])
            ->selectRaw("COALESCE(SUM($formula), 0) as total")
            ->value('total'), 2);
    }

    private function movimientos(string $desde, string $hasta, ?string $fondoFiltro): array
    {
        $gastos = DB::table('gastos as g')
            ->leftJoin('gasto_categorias as gc', 'gc.categoria_id', '=', 'g.categoria_id')
            ->select([
                'g.gasto_id as id',
                'g.fecha',
                'g.fondo',
                'g.descripcion as titulo',
                'g.monto',
                'g.nota',
                'g.estado',
                'g.motivo_anulacion',
                'gc.nombre as categoria',
            ])
            ->whereBetween('g.fecha', [$desde, $hasta])
            ->when($fondoFiltro, fn ($query) => $query->where('g.fondo', $fondoFiltro))
            ->orderByDesc('g.fecha')
            ->orderByDesc('g.gasto_id')
            ->limit(80)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'fecha' => $item->fecha,
                    'fondo' => $item->fondo,
                    'tipo' => 'GASTO',
                    'titulo' => $item->titulo,
                    'categoria' => $item->categoria ?? 'Sin categoria',
                    'monto' => (float) $item->monto,
                    'nota' => $item->nota,
                    'estado' => $item->estado ?? 'ACTIVO',
                    'motivo_anulacion' => $item->motivo_anulacion,
                ];
            })
            ->all();

        $capitales = $this->movimientosCapital($desde, $hasta, $fondoFiltro);
        $compras = $this->movimientosCompras($desde, $hasta, $fondoFiltro);

        return collect([...$gastos, ...$capitales, ...$compras])
            ->sortByDesc('fecha')
            ->take(120)
            ->values()
            ->all();
    }

    private function movimientosCapital(string $desde, string $hasta, ?string $fondoFiltro): array
    {
        if (!Schema::hasTable('gasto_capitales')) {
            return [];
        }

        return DB::table('gasto_capitales')
            ->select([
                'capital_id as id',
                'fecha',
                'fondo',
                'descripcion as titulo',
                'monto',
                'nota',
                'estado',
                'motivo_anulacion',
            ])
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($fondoFiltro, fn ($query) => $query->where('fondo', $fondoFiltro))
            ->orderByDesc('fecha')
            ->orderByDesc('capital_id')
            ->limit(80)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'fecha' => $item->fecha,
                    'fondo' => $item->fondo,
                    'tipo' => 'CAPITAL',
                    'titulo' => $item->titulo,
                    'categoria' => 'Capital agregado',
                    'monto' => (float) $item->monto,
                    'nota' => $item->nota,
                    'estado' => $item->estado ?? 'ACTIVO',
                    'motivo_anulacion' => $item->motivo_anulacion,
                ];
            })
            ->all();
    }

    private function movimientosCompras(string $desde, string $hasta, ?string $fondoFiltro): array
    {
        $movimientos = [];

        if (!$fondoFiltro || $fondoFiltro === self::FONDO_POLLO) {
            $movimientos = array_merge($movimientos, DB::table('entregas_proveedor')
                ->select([
                    'entrega_id as id',
                    DB::raw('DATE(fecha_hora) as fecha'),
                    DB::raw("'" . self::FONDO_POLLO . "' as fondo"),
                    DB::raw("CONCAT('Compra ', COALESCE(tipo, 'pollo/gallina')) as titulo"),
                    'costo_total as monto',
                    DB::raw('NULL as nota'),
                    DB::raw("'ACTIVO' as estado"),
                    DB::raw('NULL as motivo_anulacion'),
                ])
                ->whereBetween(DB::raw('DATE(fecha_hora)'), [$desde, $hasta])
                ->where(function ($query) {
                    $query
                        ->whereRaw('UPPER(tipo) LIKE ?', ['%POLLO%'])
                        ->orWhereRaw('UPPER(tipo) LIKE ?', ['%GALLINA%']);
                })
                ->limit(80)
                ->get()
                ->map(fn ($item) => $this->formatearMovimientoCompra($item))
                ->all());
        }

        if ((!$fondoFiltro || $fondoFiltro === self::FONDO_OTROS) && Schema::hasTable('compras_lote') && Schema::hasTable('compras_lote_detalle')) {
            $usaCostoTotalCompra = Schema::hasColumn('compras_lote_detalle', 'costo_total_compra');
            $formula = $usaCostoTotalCompra
                ? 'COALESCE(cld.costo_total_compra, cld.costo_kilo * cld.cantidad)'
                : 'cld.costo_kilo * cld.cantidad';

            $movimientos = array_merge($movimientos, DB::table('compras_lote as cl')
                ->join('compras_lote_detalle as cld', 'cl.compra_lote_id', '=', 'cld.compra_lote_id')
                ->join('productos as p', 'p.producto_id', '=', 'cld.producto_id')
                ->select([
                    'cld.compra_lote_detalle_id as id',
                    'cl.fecha_ingreso as fecha',
                    DB::raw("'" . self::FONDO_OTROS . "' as fondo"),
                    DB::raw("CONCAT('Compra ', p.nombre) as titulo"),
                    DB::raw("$formula as monto"),
                    'cl.codigo_comprobante as nota',
                    DB::raw("'ACTIVO' as estado"),
                    DB::raw('NULL as motivo_anulacion'),
                ])
                ->whereBetween('cl.fecha_ingreso', [$desde, $hasta])
                ->limit(80)
                ->get()
                ->map(fn ($item) => $this->formatearMovimientoCompra($item))
                ->all());
        }

        return $movimientos;
    }

    private function formatearMovimientoCompra(object $item): array
    {
        return [
            'id' => (int) $item->id,
            'fecha' => $item->fecha,
            'fondo' => $item->fondo,
            'tipo' => 'COMPRA',
            'titulo' => $item->titulo,
            'categoria' => 'Pago de compra',
            'monto' => (float) $item->monto,
            'nota' => $item->nota,
            'estado' => $item->estado ?? 'ACTIVO',
            'motivo_anulacion' => $item->motivo_anulacion,
        ];
    }

    private function auditoria(string $desde, string $hasta, ?string $fondoFiltro): array
    {
        return DB::table('gasto_auditoria as ga')
            ->leftJoin('usuarios as u', 'u.usuario_id', '=', 'ga.usuario_id')
            ->select([
                'ga.auditoria_id',
                'ga.accion',
                'ga.entidad',
                'ga.entidad_id',
                'ga.fondo',
                'ga.descripcion',
                'ga.creado_en',
                'u.nombres',
                'u.apellidos',
                'u.usuario',
            ])
            ->whereBetween(DB::raw('DATE(ga.creado_en)'), [$desde, $hasta])
            ->when($fondoFiltro, fn ($query) => $query->where('ga.fondo', $fondoFiltro))
            ->orderByDesc('ga.creado_en')
            ->orderByDesc('ga.auditoria_id')
            ->limit(120)
            ->get()
            ->map(function ($item) {
                $nombre = trim((string) (($item->nombres ?? '') . ' ' . ($item->apellidos ?? '')));

                return [
                    'auditoria_id' => (int) $item->auditoria_id,
                    'accion' => $item->accion,
                    'entidad' => $item->entidad,
                    'entidad_id' => $item->entidad_id,
                    'fondo' => $item->fondo,
                    'descripcion' => $item->descripcion,
                    'creado_en' => $item->creado_en,
                    'usuario' => $nombre !== '' ? $nombre : ($item->usuario ?? 'Usuario'),
                ];
            })
            ->all();
    }

    private function cierresMensuales()
    {
        return DB::table('gasto_cierres_mensuales')
            ->orderByDesc('periodo')
            ->limit(24)
            ->get();
    }

    private function categoriasBase()
    {
        $base = ['Personal', 'Local', 'Servicios', 'Comida', 'Ropa', 'Transporte', 'Compras personales', 'Otros'];

        foreach ($base as $nombre) {
            DB::table('gasto_categorias')->updateOrInsert(['nombre' => $nombre], ['nombre' => $nombre]);
        }

        return DB::table('gasto_categorias')->orderBy('nombre')->get();
    }

    private function ventaPolloGallina(string $fecha, ?int $usuarioId = null)
    {
        $query = DB::table('ventas_pollo_gallina_diarias')->where('fecha', Carbon::parse($fecha)->toDateString());

        if ($usuarioId) {
            $query->where('usuario_id', $usuarioId);
        }

        return $query->first();
    }

    private function resolverRango(Request $request): array
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        $desde = $request->query('fecha_desde', $hoy);
        $hasta = $request->query('fecha_hasta', $desde);

        return [
            Carbon::parse($desde)->toDateString(),
            Carbon::parse($hasta)->toDateString(),
        ];
    }

    private function unirObservaciones(?string $actual, ?string $nueva): ?string
    {
        $actual = trim((string) $actual);
        $nueva = trim((string) $nueva);

        if ($nueva === '') {
            return $actual !== '' ? $actual : null;
        }

        if ($actual === '') {
            return $nueva;
        }

        return mb_substr($actual . ' | ' . $nueva, 0, 250);
    }

    private function periodoEstaCerrado(string $fecha): bool
    {
        $periodo = Carbon::parse($fecha)->format('Y-m');

        return DB::table('gasto_cierres_mensuales')
            ->where('periodo', $periodo)
            ->exists();
    }

    private function registrarAuditoria(
        Request $request,
        string $accion,
        string $entidad,
        ?int $entidadId,
        ?string $fondo,
        string $descripcion,
        mixed $antes,
        mixed $despues
    ): void {
        DB::table('gasto_auditoria')->insert([
            'usuario_id' => $request->user()?->usuario_id,
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'fondo' => $fondo,
            'descripcion' => $descripcion,
            'datos_antes' => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'datos_despues' => $despues ? json_encode($despues, JSON_UNESCAPED_UNICODE) : null,
            'creado_en' => now(),
        ]);
    }
}
