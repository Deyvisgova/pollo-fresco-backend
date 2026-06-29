<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntregaProveedor;
use App\Models\PagoProveedor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PagoProveedorController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($this->construirConsulta($request)->get());
    }

    public function pdf(Request $request)
    {
        $pagos = $this->construirConsulta($request)->get();

        $html = view('pdf.pagos-proveedor', [
            'pagos' => $pagos,
            'fechaGeneracion' => now()->format('d/m/Y H:i:s'),
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download('historial-pagos-proveedor.pdf');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'usuario_id' => ['required', 'integer', 'exists:usuarios,usuario_id'],
            'entregas_ids' => ['required', 'array', 'min:1'],
            'entregas_ids.*' => ['integer', 'exists:entregas_proveedor,entrega_id'],
            'monto_transferencia' => ['required', 'numeric', 'min:0'],
            'monto_efectivo' => ['required', 'numeric', 'min:0'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
        ]);

        return DB::transaction(function () use ($validated) {
            $entregas = EntregaProveedor::query()
                ->whereIn('entrega_id', $validated['entregas_ids'])
                ->when(Schema::hasColumn('entregas_proveedor', 'estado_pago'), function ($query) {
                    $query->where('estado_pago', 'PENDIENTE');
                })
                ->with('proveedor')
                ->lockForUpdate()
                ->get();

            if ($entregas->isEmpty()) {
                return response()->json(['message' => 'No hay entregas pendientes para pagar.'], 422);
            }

            $total = (float) $entregas->sum(fn (EntregaProveedor $entrega) => (float) $entrega->costo_total);
            $pagado = (float) $validated['monto_transferencia'] + (float) $validated['monto_efectivo'];
            $saldo = round($total - $pagado, 2);
            $estado = abs($saldo) < 0.0001 ? 'PAGADO' : 'PENDIENTE';

            $proveedores = $entregas
                ->map(fn (EntregaProveedor $entrega) => trim((string) ($entrega->proveedor?->nombres . ' ' . ($entrega->proveedor?->apellidos ?? ''))))
                ->filter(fn (string $nombre) => $nombre !== '')
                ->unique()
                ->values();

            $dataPago = [
                'usuario_id' => $validated['usuario_id'],
                'total' => $total,
                'monto_transferencia' => $validated['monto_transferencia'],
                'monto_efectivo' => $validated['monto_efectivo'],
                'saldo' => $saldo,
                'estado' => $estado,
                'fecha_desde' => $validated['fecha_desde'] ?? null,
                'fecha_hasta' => $validated['fecha_hasta'] ?? null,
                'cantidad_entregas' => $entregas->count(),
            ];

            if (Schema::hasColumn('pagos_proveedor', 'proveedor_id')) {
                $dataPago['proveedor_id'] = (int) $entregas->first()->proveedor_id;
            }

            if (Schema::hasColumn('pagos_proveedor', 'proveedor_pagado')) {
                $dataPago['proveedor_pagado'] = $proveedores->join(', ');
            }

            $pago = PagoProveedor::create($dataPago);

            if ($estado === 'PAGADO' && Schema::hasColumn('entregas_proveedor', 'estado_pago')) {
                EntregaProveedor::whereIn('entrega_id', $entregas->pluck('entrega_id'))->update(['estado_pago' => 'PAGADO']);
            }

            return response()->json($pago, 201);
        });
    }

    private function construirConsulta(Request $request): Builder
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $proveedorId = (int) $request->query('proveedor_id', 0);
        $fecha = trim((string) $request->query('fecha', ''));

        $query = PagoProveedor::query()
            ->when(Schema::hasColumn('pagos_proveedor', 'proveedor_id'), function ($builder) {
                $builder->with('proveedor');
            })
            ->orderByDesc('creado_en')
            ->orderByDesc('pago_id');

        if ($search !== '') {
            $query->where(function ($subquery) use ($search) {
                $subquery
                    ->whereRaw('LOWER(estado) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(pago_id AS CHAR) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($proveedorId > 0 && Schema::hasColumn('pagos_proveedor', 'proveedor_id')) {
            $query->where('proveedor_id', $proveedorId);
        }

        if ($fecha !== '') {
            $query->whereDate('creado_en', $fecha);
        }

        return $query;
    }
}
