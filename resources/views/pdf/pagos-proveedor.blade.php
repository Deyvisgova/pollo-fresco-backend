<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
    h2 { margin: 0 0 8px; }
    .meta { margin-bottom: 12px; color: #374151; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d1d5db; padding: 6px; text-align: left; }
    th { background: #e5e7eb; }
  </style>
</head>
<body>
  <h2>Historial de pago de proveedores</h2>
  <div class="meta">Generado: {{ $fechaGeneracion }}</div>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Proveedor</th>
        <th>Total</th>
        <th>Transferencia</th>
        <th>Efectivo</th>
        <th>Saldo</th>
        <th>Estado</th>
        <th>Entregas</th>
        <th>Rango fechas</th>
        <th>Creado en</th>
      </tr>
    </thead>
    <tbody>
      @forelse($pagos as $pago)
        @php
          $proveedorPagado = trim((string)($pago->proveedor_pagado ?? ''));
          $nombreProveedor = trim((string)(($pago->proveedor->nombres ?? '') . ' ' . ($pago->proveedor->apellidos ?? '')));
          $textoProveedor = $proveedorPagado !== '' ? $proveedorPagado : ($nombreProveedor !== '' ? $nombreProveedor : ($pago->proveedor_id ? 'Proveedor #' . $pago->proveedor_id : '-'));
        @endphp
        <tr>
          <td>{{ $pago->pago_id }}</td>
          <td>{{ $textoProveedor }}</td>
          <td>S/ {{ number_format((float) $pago->total, 2) }}</td>
          <td>S/ {{ number_format((float) $pago->monto_transferencia, 2) }}</td>
          <td>S/ {{ number_format((float) $pago->monto_efectivo, 2) }}</td>
          <td>S/ {{ number_format((float) $pago->saldo, 2) }}</td>
          <td>{{ $pago->estado }}</td>
          <td>{{ $pago->cantidad_entregas }}</td>
          <td>{{ $pago->fecha_desde ? \Carbon\Carbon::parse($pago->fecha_desde)->format('d/m/Y') : '-' }} a {{ $pago->fecha_hasta ? \Carbon\Carbon::parse($pago->fecha_hasta)->format('d/m/Y') : '-' }}</td>
          <td>{{ \Carbon\Carbon::parse($pago->creado_en)->format('d/m/Y H:i') }}</td>
        </tr>
      @empty
        <tr><td colspan="10">Sin pagos para los filtros seleccionados.</td></tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
