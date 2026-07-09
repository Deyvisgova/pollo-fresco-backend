<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntregaProveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EntregaProveedorController extends Controller
{
    /**
     * Listar entregas de proveedores.
     */
    public function index(Request $request)
    {
        $proveedorId = $request->query('proveedor_id');
        $fechaHora = $request->query('fecha_hora');
        $fechaDesde = $request->query('fecha_desde');
        $fechaHasta = $request->query('fecha_hasta');

        $query = EntregaProveedor::with('proveedor')
            ->orderByDesc('fecha_hora')
            ->orderByDesc('entrega_id');

        if ($proveedorId) {
            $query->where('proveedor_id', $proveedorId);
        }

        if ($fechaHora) {
            $query->whereDate('fecha_hora', $fechaHora);
        }

        if ($fechaDesde) {
            $query->whereDate('fecha_hora', '>=', $fechaDesde);
        }

        if ($fechaHasta) {
            $query->whereDate('fecha_hora', '<=', $fechaHasta);
        }

        return response()->json($query->get());
    }

    /**
     * Registrar una entrega por línea.
     */
    public function store(Request $request)
    {
        $esVendedor = in_array($request->user()?->role, ['vendor', 'cashier'], true);
        $rules = [
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,proveedor_id'],
            'usuario_id' => ['nullable', 'integer', 'exists:usuarios,usuario_id'],
            'fecha_hora' => ['required', 'date'],
            'cantidad_pollos' => ['required', 'integer', 'min:0'],
            'peso_total_kg' => ['required', 'numeric', 'min:0'],
            'merma_kg' => ['nullable', 'numeric', 'min:0'],
            'costo_total' => ['nullable', 'numeric', 'min:0'],
            'precio_kg' => ['nullable', 'numeric', 'min:0'],
            'tipo' => ['required', 'string', 'max:50'],
        ];

        if ($this->soportaEstadoPago()) {
            $rules['estado_pago'] = ['nullable', 'string', 'in:PENDIENTE,PAGADO'];
        }

        $validated = $request->validate($rules);

        if ($esVendedor && date('Y-m-d', strtotime($validated['fecha_hora'])) !== now('America/Lima')->toDateString()) {
            return response()->json(['message' => 'El vendedor solo puede registrar entregas del dia actual.'], 403);
        }

        $data = [
            'proveedor_id' => $validated['proveedor_id'],
            'usuario_id' => $request->user()->id,
            'fecha_hora' => $validated['fecha_hora'],
            'cantidad_pollos' => $validated['cantidad_pollos'],
            'peso_total_kg' => $validated['peso_total_kg'],
            'merma_kg' => $esVendedor ? 0.0 : ($validated['merma_kg'] ?? 0.0),
            'costo_total' => $esVendedor ? 0.0 : ($validated['costo_total'] ?? 0.0),
            'tipo' => $validated['tipo'],
        ];

        if ($this->soportaEstadoPago()) {
            $data['estado_pago'] = $esVendedor ? 'PENDIENTE' : ($validated['estado_pago'] ?? 'PENDIENTE');
        }

        $entrega = EntregaProveedor::create($data);

        return response()->json($entrega->load('proveedor'), 201);
    }

    /**
     * Actualizar una fila de entrega.
     */
    public function update(Request $request, EntregaProveedor $entregaProveedor)
    {
        $rules = [
            'fecha_hora' => ['required', 'date'],
            'cantidad_pollos' => ['required', 'integer', 'min:0'],
            'peso_total_kg' => ['required', 'numeric', 'min:0'],
            'merma_kg' => ['required', 'numeric', 'min:0'],
            'costo_total' => ['required', 'numeric', 'min:0'],
            'tipo' => ['required', 'string', 'max:50'],
        ];

        if ($this->soportaEstadoPago()) {
            $rules['estado_pago'] = ['sometimes', 'string', 'in:PENDIENTE,PAGADO'];
        }

        $validated = $request->validate($rules);

        $entregaProveedor->update($validated);

        return response()->json($entregaProveedor->load('proveedor'));
    }

    /**
     * Eliminar una fila de entrega.
     */
    public function destroy(EntregaProveedor $entregaProveedor)
    {
        $entregaProveedor->delete();

        return response()->json(['message' => 'Entrega eliminada']);
    }

    private function soportaEstadoPago(): bool
    {
        return Schema::hasColumn('entregas_proveedor', 'estado_pago');
    }
}
