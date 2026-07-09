<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ConfiguracionController extends Controller
{
    public function mostrarEmpresa()
    {
        $configuracion = $this->obtenerConfiguracionEmpresa();

        return response()->json([
            'nombre_empresa' => $configuracion->nombre_empresa,
            'logo_url' => $configuracion->logo_url,
        ]);
    }

    public function guardarEmpresa(Request $request)
    {
        $validated = $request->validate([
            'nombre_empresa' => ['required', 'string', 'max:180'],
        ]);

        $configuracion = $this->obtenerConfiguracionEmpresa();

        DB::table('configuracion_empresa')
            ->where('configuracion_empresa_id', $configuracion->configuracion_empresa_id)
            ->update([
                'nombre_empresa' => trim((string) $validated['nombre_empresa']) ?: 'Nombre de la empresa',
                'actualizado_en' => now(),
            ]);

        return $this->mostrarEmpresa();
    }

    public function subirLogo(Request $request)
    {
        $validated = $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
        ]);

        $archivoLogo = $validated['logo'];
        $mimeType = (string) $archivoLogo->getMimeType();

        if (! str_starts_with($mimeType, 'image/')) {
            return response()->json([
                'message' => 'El archivo debe ser una imagen valida.',
            ], 422);
        }

        $contenido = file_get_contents($archivoLogo->getRealPath());
        $imagen = $contenido !== false ? @imagecreatefromstring($contenido) : false;
        if ($imagen === false) {
            return response()->json(['message' => 'La imagen no se pudo procesar.'], 422);
        }

        $nombreArchivo = 'logo-empresa-' . Str::uuid() . '.webp';
        $rutaLogoPublica = public_path('assets/images/logo');

        if (! File::exists($rutaLogoPublica)) {
            File::makeDirectory($rutaLogoPublica, 0755, true);
        }

        $destino = $rutaLogoPublica . DIRECTORY_SEPARATOR . $nombreArchivo;
        $guardado = imagewebp($imagen, $destino, 82);
        imagedestroy($imagen);

        if (! $guardado) {
            return response()->json(['message' => 'No se pudo guardar el logo.'], 500);
        }

        $logoUrl = asset('assets/images/logo/' . $nombreArchivo);
        $configuracion = $this->obtenerConfiguracionEmpresa();

        DB::table('configuracion_empresa')
            ->where('configuracion_empresa_id', $configuracion->configuracion_empresa_id)
            ->update([
                'logo_url' => $logoUrl,
                'actualizado_en' => now(),
            ]);

        return response()->json([
            'message' => 'Logo subido correctamente.',
            'logo_url' => $logoUrl,
        ]);
    }

    public function eliminarLogo(Request $request)
    {
        $validated = $request->validate([
            'logo_url' => ['nullable', 'string'],
        ]);

        $logoUrl = trim((string) ($validated['logo_url'] ?? ''));

        if ($logoUrl !== '') {
            $nombreArchivo = basename(parse_url($logoUrl, PHP_URL_PATH) ?: '');
            if ($nombreArchivo === '' || $nombreArchivo === '.' || $nombreArchivo === '..') {
                return response()->json([
                    'message' => 'El logo indicado no es valido.',
                ], 422);
            }

            $rutaLogoPublica = public_path('assets/images/logo/' . $nombreArchivo);
            if (File::exists($rutaLogoPublica)) {
                File::delete($rutaLogoPublica);
            }
        }

        $configuracion = $this->obtenerConfiguracionEmpresa();

        DB::table('configuracion_empresa')
            ->where('configuracion_empresa_id', $configuracion->configuracion_empresa_id)
            ->update([
                'logo_url' => null,
                'actualizado_en' => now(),
            ]);

        return response()->json([
            'message' => 'Logo eliminado correctamente.',
        ]);
    }

    private function obtenerConfiguracionEmpresa(): object
    {
        $configuracion = DB::table('configuracion_empresa')
            ->orderByDesc('configuracion_empresa_id')
            ->first();

        if ($configuracion) {
            return $configuracion;
        }

        $id = DB::table('configuracion_empresa')->insertGetId([
            'nombre_empresa' => 'POLLO FRESCO',
            'logo_url' => null,
            'creado_en' => now(),
            'actualizado_en' => now(),
        ]);

        return DB::table('configuracion_empresa')
            ->where('configuracion_empresa_id', $id)
            ->first();
    }
}
