<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ConfiguracionSunatController extends Controller
{
    public function mostrar()
    {
        $this->autorizarAdministrador(request());
        $configuracion = DB::table('configuracion_sunat')->orderByDesc('configuracion_sunat_id')->first();

        return response()->json([
            'configuracion' => $configuracion ? $this->respuestaSegura($configuracion) : null,
            'series' => DB::table('comprobante_series')->orderBy('serie_id')->get(),
        ]);
    }

    public function guardar(Request $request)
    {
        $this->autorizarAdministrador($request);
        $datos = $request->validate([
            'ambiente' => ['required', Rule::in(['beta', 'produccion'])],
            'ruc' => ['required', 'digits:11'],
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'direccion_fiscal' => ['required', 'string', 'max:250'],
            'ubigeo' => ['nullable', 'digits:6'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'distrito' => ['nullable', 'string', 'max:100'],
            'correo' => ['nullable', 'email', 'max:150'],
            'usuario_sol' => ['nullable', 'string', 'max:100'],
            'clave_sol' => ['nullable', 'string', 'max:200'],
            'certificado_clave' => ['nullable', 'string', 'max:200'],
            'activo' => ['required', 'boolean'],
        ]);

        $existente = DB::table('configuracion_sunat')->orderByDesc('configuracion_sunat_id')->first();
        $payload = collect($datos)->except(['clave_sol', 'certificado_clave'])->all();
        $payload['actualizado_por'] = $request->user()?->usuario_id;
        $payload['actualizado_en'] = now();

        if ($request->filled('clave_sol')) {
            $payload['clave_sol_encriptada'] = Crypt::encryptString((string) $request->input('clave_sol'));
        }
        if ($request->filled('certificado_clave')) {
            $payload['certificado_clave_encriptada'] = Crypt::encryptString((string) $request->input('certificado_clave'));
        }

        if ($existente) {
            DB::table('configuracion_sunat')
                ->where('configuracion_sunat_id', $existente->configuracion_sunat_id)
                ->update($payload);
            $id = $existente->configuracion_sunat_id;
        } else {
            $payload['creado_en'] = now();
            $id = DB::table('configuracion_sunat')->insertGetId($payload);
        }

        $guardado = DB::table('configuracion_sunat')->where('configuracion_sunat_id', $id)->first();

        return response()->json($this->respuestaSegura($guardado));
    }

    public function subirCertificado(Request $request)
    {
        $this->autorizarAdministrador($request);
        $request->validate([
            'certificado' => ['required', 'file', 'max:4096', 'mimes:pem,txt'],
            'certificado_clave' => ['nullable', 'string', 'max:200'],
        ]);

        $configuracion = DB::table('configuracion_sunat')->orderByDesc('configuracion_sunat_id')->first();
        if (! $configuracion) {
            return response()->json(['message' => 'Primero guarda los datos del emisor SUNAT.'], 422);
        }

        if ($configuracion->certificado_ruta) {
            Storage::disk('local')->delete($configuracion->certificado_ruta);
        }

        $contenido = file_get_contents($request->file('certificado')->getRealPath());
        if ($request->filled('certificado_clave')) {
            $clavePrivada = openssl_pkey_get_private($contenido, (string) $request->input('certificado_clave'));
            if (! $clavePrivada || ! openssl_pkey_export($clavePrivada, $clavePrivadaPem)) {
                return response()->json(['message' => 'La clave del certificado no es válida.'], 422);
            }
            preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $contenido, $certificadoPublico);
            if (empty($certificadoPublico[0])) {
                return response()->json(['message' => 'El archivo no contiene el certificado público PEM.'], 422);
            }
            $contenido = $clavePrivadaPem . PHP_EOL . $certificadoPublico[0] . PHP_EOL;
        }

        $ruta = "sunat/certificados/certificado-{$configuracion->ruc}.pem";
        Storage::disk('local')->put($ruta, $contenido);

        $payload = ['certificado_ruta' => $ruta, 'actualizado_en' => now()];

        DB::table('configuracion_sunat')
            ->where('configuracion_sunat_id', $configuracion->configuracion_sunat_id)
            ->update($payload);

        return response()->json([
            'message' => 'Certificado guardado de forma privada.',
            'certificado_configurado' => true,
        ]);
    }

    private function respuestaSegura(object $configuracion): array
    {
        return [
            'configuracion_sunat_id' => $configuracion->configuracion_sunat_id,
            'ambiente' => $configuracion->ambiente,
            'ruc' => $configuracion->ruc,
            'razon_social' => $configuracion->razon_social,
            'nombre_comercial' => $configuracion->nombre_comercial,
            'direccion_fiscal' => $configuracion->direccion_fiscal,
            'ubigeo' => $configuracion->ubigeo,
            'departamento' => $configuracion->departamento,
            'provincia' => $configuracion->provincia,
            'distrito' => $configuracion->distrito,
            'correo' => $configuracion->correo,
            'usuario_sol' => $configuracion->usuario_sol,
            'activo' => (bool) $configuracion->activo,
            'clave_sol_configurada' => ! empty($configuracion->clave_sol_encriptada),
            'certificado_configurado' => ! empty($configuracion->certificado_ruta),
            'certificado_clave_configurada' => ! empty($configuracion->certificado_clave_encriptada),
        ];
    }

    private function autorizarAdministrador(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Solo un administrador puede configurar SUNAT.');
    }
}
