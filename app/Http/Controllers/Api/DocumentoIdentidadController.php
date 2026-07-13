<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Proveedor;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class DocumentoIdentidadController extends Controller
{
    public function mostrar(string $tipo, string $numero)
    {
        validator(
            compact('tipo', 'numero'),
            [
                'tipo' => ['required', Rule::in(['dni', 'ruc'])],
                'numero' => ['required', 'digits:' . ($tipo === 'dni' ? 8 : 11)],
            ]
        )->validate();

        $registroLocal = $this->buscarRegistroLocal($tipo, $numero);
        if ($registroLocal) {
            return response()->json([
                'success' => true,
                'data' => $registroLocal,
                'source' => 'local',
            ]);
        }

        $token = config('services.apiperu.token');
        if (! $token) {
            return response()->json([
                'message' => 'Configura APIPERU_TOKEN en el servidor para consultar SUNAT/RENIEC.',
            ], 503);
        }

        $respuesta = Http::acceptJson()
            ->timeout(12)
            ->retry(2, 300)
            ->get("https://apiperu.dev/api/{$tipo}/{$numero}", ['api_token' => $token]);

        if (! $respuesta->successful()) {
            return response()->json([
                'message' => 'No se pudo consultar el documento. Verifica el número e inténtalo nuevamente.',
            ], $respuesta->status() >= 500 ? 503 : 422);
        }

        return response()->json($respuesta->json());
    }

    private function buscarRegistroLocal(string $tipo, string $numero): ?array
    {
        $columna = $tipo === 'dni' ? 'dni' : 'ruc';

        $cliente = Cliente::query()->where($columna, $numero)->first();
        if ($cliente) {
            return $this->mapearRegistroLocal($tipo, $numero, [
                'nombres' => $cliente->nombres,
                'apellidos' => $cliente->apellidos,
                'nombre_empresa' => $cliente->nombre_empresa,
                'direccion' => $cliente->direccion_fiscal ?: $cliente->direccion,
                'telefono' => $cliente->celular,
            ]);
        }

        $proveedor = Proveedor::query()->where($columna, $numero)->first();
        if ($proveedor) {
            return $this->mapearRegistroLocal($tipo, $numero, [
                'nombres' => $proveedor->nombres,
                'apellidos' => $proveedor->apellidos,
                'nombre_empresa' => $proveedor->nombre_empresa,
                'direccion' => $proveedor->direccion,
                'telefono' => $proveedor->telefono,
            ]);
        }

        return null;
    }

    private function mapearRegistroLocal(string $tipo, string $numero, array $registro): array
    {
        $nombres = trim((string) ($registro['nombres'] ?? ''));
        $apellidos = trim((string) ($registro['apellidos'] ?? ''));
        $empresa = trim((string) ($registro['nombre_empresa'] ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);

        if ($tipo === 'ruc') {
            return [
                'ruc' => $numero,
                'numero' => $numero,
                'nombre_o_razon_social' => $empresa ?: $nombreCompleto,
                'razon_social' => $empresa ?: $nombreCompleto,
                'nombre_comercial' => $empresa,
                'direccion' => $registro['direccion'] ?? '',
                'telefono' => $registro['telefono'] ?? '',
            ];
        }

        return [
            'dni' => $numero,
            'numero' => $numero,
            'nombres' => $nombres,
            'apellido' => $apellidos,
            'nombre_completo' => $nombreCompleto ?: $empresa,
            'telefono' => $registro['telefono'] ?? '',
            'direccion' => $registro['direccion'] ?? '',
        ];
    }
}
