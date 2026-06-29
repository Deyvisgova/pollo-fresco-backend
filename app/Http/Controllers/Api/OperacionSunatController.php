<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Facturacion\FacturacionElectronicaService;
use Illuminate\Http\Request;

class OperacionSunatController extends Controller
{
    public function __construct(
        private readonly FacturacionElectronicaService $facturacion
    ) {
    }

    public function resumenes(Request $request)
    {
        $this->autorizar($request);
        return response()->json($this->facturacion->listarResumenes());
    }

    public function enviarResumen(Request $request)
    {
        $this->autorizar($request);
        $datos = $request->validate(['fecha' => ['required', 'date']]);

        try {
            return response()->json(
                $this->facturacion->enviarResumenBoletas($datos['fecha'], $request->user()?->usuario_id),
                201
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function consultarResumen(Request $request, int $resumenId)
    {
        $this->autorizar($request);
        try {
            return response()->json($this->facturacion->consultarResumen($resumenId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function bajas(Request $request)
    {
        $this->autorizar($request);
        return response()->json($this->facturacion->listarComunicacionesBaja());
    }

    public function enviarBaja(Request $request, int $ventaId)
    {
        $this->autorizar($request);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:250']]);
        try {
            return response()->json(
                $this->facturacion->enviarComunicacionBaja($ventaId, $datos['motivo'], $request->user()?->usuario_id),
                201
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function consultarBaja(Request $request, int $bajaId)
    {
        $this->autorizar($request);
        try {
            return response()->json($this->facturacion->consultarComunicacionBaja($bajaId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Solo un administrador puede operar con SUNAT.');
    }
}
