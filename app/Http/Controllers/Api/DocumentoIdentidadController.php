<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
