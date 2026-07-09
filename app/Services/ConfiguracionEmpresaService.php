<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ConfiguracionEmpresaService
{
    public function obtener(): object
    {
        $configuracion = DB::table('configuracion_empresa')
            ->orderByDesc('configuracion_empresa_id')
            ->first();

        return (object) [
            'nombre_empresa' => trim((string) ($configuracion->nombre_empresa ?? 'POLLO FRESCO')) ?: 'POLLO FRESCO',
            'logo_url' => trim((string) ($configuracion->logo_url ?? '')),
        ];
    }

    public function logoDataUri(): ?string
    {
        $logoUrl = $this->obtener()->logo_url;
        if ($logoUrl === '') {
            return null;
        }

        $ruta = parse_url($logoUrl, PHP_URL_PATH) ?: $logoUrl;
        $nombreArchivo = basename($ruta);
        if ($nombreArchivo === '' || $nombreArchivo === '.' || $nombreArchivo === '..') {
            return null;
        }

        $rutaLocal = public_path('assets/images/logo/' . $nombreArchivo);
        if (! is_file($rutaLocal)) {
            return null;
        }

        $contenido = file_get_contents($rutaLocal);
        if ($contenido === false) {
            return null;
        }

        $mime = mime_content_type($rutaLocal) ?: 'image/webp';

        return 'data:' . $mime . ';base64,' . base64_encode($contenido);
    }
}
