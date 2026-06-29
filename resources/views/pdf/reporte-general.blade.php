<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #17233b; font-size: 9px; }
        h1 { margin: 0 0 4px; font-size: 18px; color: #12376c; }
        .meta { margin-bottom: 14px; color: #566982; }
        .kpis { width: 100%; border-spacing: 7px; margin: 0 -7px 14px; }
        .kpis td { width: 25%; padding: 10px; border: 1px solid #cbd8e8; background: #f4f7fb; }
        .kpis span { display: block; color: #60708c; font-size: 8px; text-transform: uppercase; }
        .kpis strong { display: block; margin-top: 4px; font-size: 14px; }
        table.datos { width: 100%; border-collapse: collapse; }
        .datos th { padding: 7px; color: #163568; background: #e8f0fb; text-align: left; border: 1px solid #cbd8e8; }
        .datos td { padding: 6px; border: 1px solid #d8e1ed; vertical-align: top; }
        .dinero { text-align: right; white-space: nowrap; }
        .pie { position: fixed; right: 0; bottom: -12px; color: #718096; font-size: 8px; }
    </style>
</head>
<body>
    <h1>POLLO FRESCO - {{ $titulo }}</h1>
    <div class="meta">Periodo: {{ $datos['fecha_desde'] }} al {{ $datos['fecha_hasta'] }} | Generado: {{ $generadoEn }}</div>

    @if ($vista === 'resumen')
        @php
            $r = $datos['resumen'];
        @endphp
        <table class="kpis">
            <tr>
                <td><span>Resultado neto</span><strong>S/ {{ number_format($r['resultado_neto'], 2) }}</strong></td>
                <td><span>Ventas generadas</span><strong>S/ {{ number_format($r['ventas_generadas'] ?? 0, 2) }}</strong></td>
                <td><span>Dinero cobrado</span><strong>S/ {{ number_format($r['dinero_cobrado'] ?? ($r['cobrado_periodo'] ?? 0), 2) }}</strong></td>
                <td><span>Ganancia bruta</span><strong>S/ {{ number_format($r['ganancia_bruta'], 2) }}</strong></td>
            </tr>
            <tr>
                <td><span>Gastos</span><strong>S/ {{ number_format($r['gastos'], 2) }}</strong></td>
                <td><span>Cuentas por cobrar</span><strong>S/ {{ number_format($r['cuentas_por_cobrar'], 2) }}</strong></td>
                <td><span>Deuda proveedores</span><strong>S/ {{ number_format($r['deuda_proveedores'], 2) }}</strong></td>
                <td><span>Valor stock</span><strong>S/ {{ number_format($r['valor_stock'], 2) }}</strong></td>
            </tr>
            <tr>
                <td><span>Venta pollo + gallina</span><strong>S/ {{ number_format($r['ventas_pollo_gallina'], 2) }}</strong></td>
                <td><span>Venta otros productos</span><strong>S/ {{ number_format($r['ventas_otros_productos'], 2) }}</strong></td>
            </tr>
        </table>
    @else
        @php
            $config = [
                'ventas' => ['ventas', ['fecha'=>'Fecha','tipo'=>'Origen','cliente_producto'=>'Cliente / producto','detalle'=>'Detalle','total'=>'Total','costo'=>'Costo','ganancia'=>'Ganancia','saldo'=>'Saldo']],
                'stock' => ['stock', ['producto'=>'Producto','grupo'=>'Grupo','stock'=>'Stock','unidad'=>'Unidad','lotes_abiertos'=>'Lotes','valor_stock'=>'Valor stock']],
                'cobrar' => ['cuentas_cobrar', ['cliente'=>'Cliente','celular'=>'Contacto','pedidos'=>'Pedidos','total'=>'Total','pagado'=>'Pagado','saldo'=>'Saldo','deuda_desde'=>'Deuda desde']],
                'proveedores' => ['proveedores', ['proveedor'=>'Proveedor','telefono'=>'Telefono','entregas'=>'Entregas','compras'=>'Compras','pendiente'=>'Pendiente','ultima_entrega'=>'Ultima entrega']],
                'delivery' => ['delivery', ['delivery'=>'Deliverista','asignados'=>'Asignados','entregados'=>'Entregados','no_entregados'=>'No entregados','efectividad'=>'Efectividad %','minutos_promedio'=>'Tiempo promedio','total_entregado'=>'Total entregado']],
                'gastos' => ['gastos', ['fecha'=>'Fecha','fondo'=>'Fondo','categoria'=>'Categoria','descripcion'=>'Descripcion','usuario'=>'Responsable','estado'=>'Estado','monto'=>'Monto']],
                'auditoria' => ['auditoria', ['fecha'=>'Fecha','usuario'=>'Usuario','accion'=>'Accion','entidad'=>'Entidad','fondo'=>'Fondo','descripcion'=>'Descripcion']],
            ];
            [$llave, $columnas] = $config[$vista];
            $filas = $datos[$llave] ?? [];
            $camposDinero = ['total','costo','ganancia','saldo','valor_stock','pagado','compras','pendiente','total_entregado','monto'];
        @endphp
        <table class="datos">
            <thead><tr>@foreach($columnas as $etiqueta)<th>{{ $etiqueta }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($filas as $fila)
                <tr>
                @foreach($columnas as $campo => $etiqueta)
                    <td class="{{ in_array($campo, $camposDinero, true) ? 'dinero' : '' }}">
                        @if(in_array($campo, $camposDinero, true)) S/ {{ number_format((float)($fila[$campo] ?? 0), 2) }}
                        @else {{ $fila[$campo] ?? '-' }}
                        @endif
                    </td>
                @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columnas) }}">No hay registros para el periodo seleccionado.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
    <div class="pie">Reporte generado por el sistema Pollo Fresco</div>
</body>
</html>
