<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

class ProveedorController extends Controller
{
    /**
     * Listar proveedores, con búsqueda opcional.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Proveedor::query();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('nombres', 'like', "%{$search}%")
                    ->orWhere('apellidos', 'like', "%{$search}%")
                    ->orWhere('ruc', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('nombre_empresa', 'like', "%{$search}%")
                    ->orWhere('telefono', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('nombres')->get()
        );
    }

    /**
     * Guardar un nuevo proveedor.
     */
    public function store(Request $request)
    {
        $this->normalizarRequest($request);
        $validated = $this->normalizarPayload($this->validatePayload($request));

        $proveedor = Proveedor::create($validated);

        return response()->json($proveedor, 201);
    }

    /**
     * Actualizar un proveedor existente.
     */
    public function update(Request $request, int $proveedor)
    {
        $this->normalizarRequest($request);
        $proveedorModel = Proveedor::findOrFail($proveedor);
        $validated = $this->normalizarPayload($this->validatePayload($request, $proveedorModel->proveedor_id));

        $proveedorModel->fill($validated)->save();

        return response()->json($proveedorModel);
    }

    /**
     * Mostrar un proveedor.
     */
    public function show(int $proveedor)
    {
        return response()->json(Proveedor::findOrFail($proveedor));
    }

    /**
     * Eliminar un proveedor.
     */
    public function destroy(int $proveedor)
    {
        $proveedorModel = Proveedor::findOrFail($proveedor);

        if (
            DB::table('entregas_proveedor')->where('proveedor_id', $proveedorModel->proveedor_id)->exists()
            || DB::table('compras_lote')->where('proveedor_id', $proveedorModel->proveedor_id)->exists()
        ) {
            return response()->json([
                'message' => 'No se puede eliminar este proveedor porque tiene entregas o lotes registrados. Puedes editar sus datos, pero no borrarlo.'
            ], 409);
        }

        try {
            $proveedorModel->delete();
        } catch (QueryException $exception) {
            return response()->json([
                'message' => 'No se puede eliminar este proveedor porque tiene registros relacionados en el sistema.'
            ], 409);
        }

        return response()->json(['message' => 'Proveedor eliminado correctamente.']);
    }



    /**
     * Limpiar documento antes de validar.
     */
    private function normalizarRequest(Request $request): void
    {
        $request->merge([
            'dni' => ($request->filled('dni') ? trim((string) $request->input('dni')) : null),
            'ruc' => ($request->filled('ruc') ? trim((string) $request->input('ruc')) : null),
        ]);
    }

    /**
     * Reglas de validación compartidas.
     */
    private function validatePayload(Request $request, ?int $proveedorId = null): array
    {
        return $request->validate([
            'dni' => [
                'nullable',
                'string',
                'size:8',
                Rule::unique('proveedores', 'dni')->ignore($proveedorId, 'proveedor_id'),
            ],
            'ruc' => [
                'nullable',
                'string',
                'size:11',
                Rule::unique('proveedores', 'ruc')->ignore($proveedorId, 'proveedor_id'),
            ],
            'nombre_empresa' => ['nullable', 'string', 'max:100'],
            'nombres' => ['nullable', 'string', 'max:80'],
            'apellidos' => ['nullable', 'string', 'max:80'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:9'],
        ]);
    }

    /**
     * Normalizar datos para tablas con columnas NOT NULL.
     */
    private function normalizarPayload(array $payload): array
    {
        $payload['nombres'] = isset($payload['nombres']) ? trim((string) $payload['nombres']) : '';
        $payload['apellidos'] = isset($payload['apellidos']) ? trim((string) $payload['apellidos']) : '';
        $payload['nombre_empresa'] = isset($payload['nombre_empresa']) ? trim((string) $payload['nombre_empresa']) : '';
        $payload['direccion'] = isset($payload['direccion']) ? trim((string) $payload['direccion']) : '';
        $payload['telefono'] = isset($payload['telefono']) ? trim((string) $payload['telefono']) : '';

        return $payload;
    }

}
