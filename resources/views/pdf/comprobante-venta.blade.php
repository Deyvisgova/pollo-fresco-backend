<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    @page { margin: {{ $formato === 'a4' ? '24px' : '10px' }}; }
    body { font-family: DejaVu Sans, sans-serif; font-size: {{ $formato === 'a4' ? '11px' : '8px' }}; color: #111827; }
    .cabecera { width: 100%; margin-bottom: 14px; }
    .cabecera td { vertical-align: top; }
    .emisor { width: 62%; }
    .documento { border: 2px solid #1d4ed8; text-align: center; padding: 10px; }
    .documento strong { display: block; font-size: 16px; margin: 4px 0; }
    h2, p { margin: 0 0 4px; }
    .datos { border: 1px solid #cbd5e1; padding: 8px; margin-bottom: 12px; }
    table.detalle { width: 100%; border-collapse: collapse; }
    .detalle th, .detalle td { border-bottom: 1px solid #dbe3ef; padding: 6px 4px; text-align: left; }
    .detalle th { background: #eef4ff; }
    .numero { text-align: right !important; white-space: nowrap; }
    .totales { width: 45%; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
    .totales td { padding: 4px; }
    .total { font-size: 14px; font-weight: bold; border-top: 2px solid #1d4ed8; }
    .pie { margin-top: 14px; text-align: center; color: #475569; }
    .qr, .qr svg { width: 105px; height: 105px; margin: 8px auto; }
    .estado { font-weight: bold; color: #1d4ed8; }
    @if($formato !== 'a4')
      .cabecera, .cabecera tbody, .cabecera tr, .cabecera td { display: block; width: 100%; }
      .emisor { text-align: center; margin-bottom: 8px; }
      .detalle th:nth-child(2), .detalle td:nth-child(2) { display: none; }
      .totales { width: 100%; }
    @endif
  </style>
</head>
<body>
  <table class="cabecera"><tr>
    <td class="emisor">
      <h2>{{ $emisor->razon_social ?? 'POLLO FRESCO' }}</h2>
      <p>{{ $emisor->nombre_comercial ?? '' }}</p>
      <p>{{ $emisor->direccion_fiscal ?? '' }}</p>
      <p>{{ trim(($emisor->distrito ?? '') . ' ' . ($emisor->provincia ?? '') . ' ' . ($emisor->departamento ?? '')) }}</p>
    </td>
    <td class="documento">
      <span>RUC {{ $emisor->ruc ?? '-' }}</span>
      <strong>{{ strtoupper(str_replace('-', ' ', $venta->tipo_comprobante)) }}</strong>
      <span>{{ $venta->serie }}-{{ $venta->numero }}</span>
    </td>
  </tr></table>
  <div class="datos">
    <p><strong>Fecha:</strong> {{ \Carbon\Carbon::parse($venta->fecha_emision)->format('d/m/Y') }}</p>
    <p><strong>Cliente:</strong> {{ $venta->cliente_nombre ?: 'Publico general' }}</p>
    <p><strong>Documento:</strong> {{ $venta->cliente_documento ?: '-' }}</p>
    <p><strong>Direccion:</strong> {{ $venta->cliente_direccion ?: '-' }}</p>
    @if($venta->referencia_serie)
      <p><strong>Documento afectado:</strong> {{ $venta->referencia_serie }}-{{ $venta->referencia_numero }}</p>
      <p><strong>Motivo:</strong> {{ $venta->referencia_motivo }}</p>
    @endif
  </div>
  <table class="detalle">
    <thead><tr><th>Cant.</th><th>Unidad</th><th>Descripcion</th><th class="numero">P. unitario</th><th class="numero">Total</th></tr></thead>
    <tbody>@foreach($detalles as $detalle)<tr>
      <td>{{ number_format((float)$detalle->cantidad, 2) }}</td><td>{{ $detalle->unidad }}</td><td>{{ $detalle->descripcion }}</td>
      <td class="numero">S/ {{ number_format((float)$detalle->precio_unitario, 2) }}</td>
      <td class="numero">S/ {{ number_format((float)$detalle->total_linea, 2) }}</td>
    </tr>@endforeach</tbody>
  </table>
  <table class="totales">
    <tr><td>Op. gravada</td><td class="numero">S/ {{ number_format((float)($venta->operacion_gravada ?? 0), 2) }}</td></tr>
    <tr><td>Op. exonerada</td><td class="numero">S/ {{ number_format((float)($venta->operacion_exonerada ?? 0), 2) }}</td></tr>
    <tr><td>IGV</td><td class="numero">S/ {{ number_format((float)($venta->igv ?? 0), 2) }}</td></tr>
    <tr class="total"><td>Total</td><td class="numero">S/ {{ number_format((float)$venta->total, 2) }}</td></tr>
  </table>
  <div class="pie">
    <div class="qr">{!! $qrSvg !!}</div>
    <p class="estado">Estado SUNAT: {{ $venta->estado_sunat ?? 'NO_ENVIADO' }}</p>
    <p>Representacion impresa del comprobante electronico.</p>
    @if($venta->codigo_hash)<p>Hash: {{ $venta->codigo_hash }}</p>@endif
  </div>
</body>
</html>
