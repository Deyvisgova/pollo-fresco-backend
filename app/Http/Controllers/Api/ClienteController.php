<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    /**
     * Listar clientes con búsqueda opcional.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Cliente::query();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('nombres', 'like', "%{$search}%")
                    ->orWhere('apellidos', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('ruc', 'like', "%{$search}%")
                    ->orWhere('celular', 'like', "%{$search}%")
                    ->orWhere('nombre_empresa', 'like', "%{$search}%")
                    ->orWhere('referencias', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('nombres')->get()
        );
    }

    /**
     * Guardar un nuevo cliente.
     */
    public function store(Request $request)
    {
        $validated = $this->normalizarPayload($this->validatePayload($request));

        $cliente = Cliente::create($validated);

        return response()->json($cliente, 201);
    }

    /**
     * Actualizar un cliente existente.
     */
    public function update(Request $request, Cliente $cliente)
    {
        $validated = $this->normalizarPayload($this->validatePayload($request, $cliente->cliente_id));

        $cliente->fill($validated)->save();

        return response()->json($cliente);
    }

    /**
     * Mostrar un cliente.
     */
    public function show(Cliente $cliente)
    {
        return response()->json($cliente);
    }

    /**
     * Eliminar un cliente.
     */
    public function destroy(Cliente $cliente)
    {
        if (DB::table('pedidos')->where('cliente_id', $cliente->cliente_id)->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar este cliente porque tiene pedidos registrados. Puedes editar sus datos, pero no borrarlo.'
            ], 409);
        }

        try {
            $cliente->delete();
        } catch (QueryException $exception) {
            return response()->json([
                'message' => 'No se puede eliminar este cliente porque tiene registros relacionados en el sistema.'
            ], 409);
        }

        return response()->json(['message' => 'Cliente eliminado correctamente.']);
    }

    /**
     * Reglas de validación compartidas.
     */
    private function validatePayload(Request $request, ?int $clienteId = null): array
    {
        return $request->validate([
            'dni' => [
                'nullable',
                'string',
                'size:8',
                Rule::unique('clientes', 'dni')->ignore($clienteId, 'cliente_id'),
            ],
            'ruc' => [
                'nullable',
                'string',
                'size:11',
                Rule::unique('clientes', 'ruc')->ignore($clienteId, 'cliente_id'),
            ],
            'nombres' => ['nullable', 'string', 'max:80'],
            'apellidos' => ['nullable', 'string', 'max:80'],
            'nombre_empresa' => ['nullable', 'string', 'max:100'],
            'celular' => ['nullable', 'string', 'size:9'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'direccion_fiscal' => ['nullable', 'string', 'max:200'],
            'referencias' => ['nullable', 'string', 'max:250'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'foto_frontis_url' => ['nullable', 'string', 'max:255'],
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
        $payload['celular'] = isset($payload['celular']) ? trim((string) $payload['celular']) : '';
        $payload['direccion'] = isset($payload['direccion']) ? trim((string) $payload['direccion']) : '';
        $payload['direccion_fiscal'] = isset($payload['direccion_fiscal']) ? trim((string) $payload['direccion_fiscal']) : '';
        $payload['referencias'] = isset($payload['referencias']) ? trim((string) $payload['referencias']) : '';
        $payload['foto_frontis_url'] = isset($payload['foto_frontis_url']) ? trim((string) $payload['foto_frontis_url']) : null;

        return $payload;
    }

}
