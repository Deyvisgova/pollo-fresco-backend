<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PaginaPublicaController extends Controller
{
    public function mostrar()
    {
        return response()->json($this->contenido());
    }

    public function guardar(Request $request)
    {
        $datos = $request->validate([
            'titulo_portada' => ['required', 'string', 'max:120'],
            'descripcion_portada' => ['required', 'string', 'max:500'],
            'slides' => ['required', 'array', 'min:1', 'max:12'],
            'slides.*.titulo' => ['required', 'string', 'max:100'],
            'slides.*.resumen' => ['required', 'string', 'max:300'],
            'slides.*.detalle' => ['nullable', 'string', 'max:400'],
            'slides.*.imagen_url' => ['nullable', 'string', 'max:500'],
            'banner_nosotros_url' => ['nullable', 'string', 'max:500'],
            'banner_productos_url' => ['nullable', 'string', 'max:500'],
            'banner_clientes_url' => ['nullable', 'string', 'max:500'],
            'banner_contacto_url' => ['nullable', 'string', 'max:500'],
            'titulo_productos' => ['required', 'string', 'max:150'],
            'descripcion_productos' => ['required', 'string', 'max:500'],
            'titulo_clientes' => ['required', 'string', 'max:150'],
            'descripcion_clientes' => ['required', 'string', 'max:500'],
            'titulo_contacto' => ['required', 'string', 'max:150'],
            'descripcion_contacto' => ['required', 'string', 'max:500'],
            'descripcion_footer' => ['required', 'string', 'max:500'],
            'productos_destacados' => ['required', 'array', 'min:1', 'max:30'],
            'productos_destacados.*.nombre' => ['required', 'string', 'max:100'],
            'productos_destacados.*.descripcion' => ['required', 'string', 'max:400'],
            'productos_destacados.*.precio' => ['nullable', 'string', 'max:60'],
            'productos_destacados.*.imagen_url' => ['nullable', 'string', 'max:500'],
            'testimonios' => ['required', 'array', 'min:1', 'max:30'],
            'testimonios.*.autor' => ['required', 'string', 'max:120'],
            'testimonios.*.texto' => ['required', 'string', 'max:500'],
            'titulo_nosotros' => ['required', 'string', 'max:150'],
            'descripcion_nosotros' => ['required', 'string', 'max:1200'],
            'mision' => ['nullable', 'string', 'max:500'],
            'vision' => ['nullable', 'string', 'max:500'],
            'valores' => ['nullable', 'string', 'max:500'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'horario' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'facebook_url' => ['nullable', 'string', 'max:500'],
            'instagram_url' => ['nullable', 'string', 'max:500'],
            'tiktok_url' => ['nullable', 'string', 'max:500'],
            'mostrar_nosotros' => ['required', 'boolean'],
            'mostrar_contacto' => ['required', 'boolean'],
        ]);

        File::ensureDirectoryExists(storage_path('app/configuracion'));
        File::put($this->ruta(), json_encode(array_merge($this->contenido(), $datos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json(['message' => 'Pagina publica actualizada.', 'contenido' => $this->contenido()]);
    }

    public function subirImagen(Request $request)
    {
        $datos = $request->validate([
            'imagen' => ['required', 'image', 'max:5120'],
            'carpeta' => ['required', 'in:slider,banners,productos'],
        ]);
        $archivo = $datos['imagen'];
        $directorio = public_path('assets/images/pagina-publica/' . $datos['carpeta']);
        File::ensureDirectoryExists($directorio);
        $dimensiones = [
            'slider' => [1600, 700],
            'banners' => [1200, 300],
            'productos' => [900, 650],
        ][$datos['carpeta']];
        $nombre = 'imagen-' . Str::uuid() . '.webp';
        $this->guardarImagenAjustada(
            $archivo->getRealPath(),
            $directorio . DIRECTORY_SEPARATOR . $nombre,
            $dimensiones[0],
            $dimensiones[1]
        );

        return response()->json([
            'imagen_url' => asset('assets/images/pagina-publica/' . $datos['carpeta'] . '/' . rawurlencode($nombre)),
        ]);
    }

    /**
     * Estira imagenes pequeñas y recorta al centro las grandes para ocupar la medida exacta.
     */
    private function guardarImagenAjustada(string $origen, string $destino, int $anchoDestino, int $altoDestino): void
    {
        $contenido = File::get($origen);
        $imagen = imagecreatefromstring($contenido);
        abort_unless($imagen !== false, 422, 'No se pudo procesar la imagen.');

        $anchoOrigen = imagesx($imagen);
        $altoOrigen = imagesy($imagen);
        $lienzo = imagecreatetruecolor($anchoDestino, $altoDestino);
        if ($anchoOrigen < $anchoDestino || $altoOrigen < $altoDestino) {
            imagecopyresampled($lienzo, $imagen, 0, 0, 0, 0, $anchoDestino, $altoDestino, $anchoOrigen, $altoOrigen);
        } else {
            $escala = max($anchoDestino / $anchoOrigen, $altoDestino / $altoOrigen);
            $anchoRecorte = max(1, (int) round($anchoDestino / $escala));
            $altoRecorte = max(1, (int) round($altoDestino / $escala));
            $xOrigen = max(0, (int) floor(($anchoOrigen - $anchoRecorte) / 2));
            $yOrigen = max(0, (int) floor(($altoOrigen - $altoRecorte) / 2));
            imagecopyresampled($lienzo, $imagen, 0, 0, $xOrigen, $yOrigen, $anchoDestino, $altoDestino, $anchoRecorte, $altoRecorte);
        }
        imagewebp($lienzo, $destino, 84);

        imagedestroy($imagen);
        imagedestroy($lienzo);
    }

    public function mostrarImagen(string $archivo)
    {
        abort_unless($archivo === basename($archivo) && preg_match('/^[A-Za-z0-9._-]+$/', $archivo), 404);
        $ruta = storage_path('app/pagina-publica/imagenes/' . $archivo);
        abort_unless(File::exists($ruta), 404);

        return response()->file($ruta, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function contenido(): array
    {
        $base = [
            'titulo_portada' => 'Pollo fresco, directo del mercado a tu cocina',
            'descripcion_portada' => 'Seleccionamos cada producto para asegurar calidad, sabor y atencion confiable para familias y negocios.',
            'slides' => [
                ['titulo' => 'Pollo entero', 'resumen' => 'Frescura diaria lista para tu cocina.', 'detalle' => 'Seleccionado y preparado para hogares y negocios.', 'imagen_url' => 'assets/images/carusel/carousel-2.svg'],
                ['titulo' => 'Cortes premium', 'resumen' => 'Cortes limpios y listos para preparar.', 'detalle' => 'Atencion cuidadosa y presentaciones para cada necesidad.', 'imagen_url' => 'assets/images/carusel/carousel-1.svg'],
                ['titulo' => 'Atencion mayorista', 'resumen' => 'Abastecimiento coordinado para tu negocio.', 'detalle' => 'Pedidos y entregas planificadas con puntualidad.', 'imagen_url' => 'assets/images/carusel/carousel-3.svg'],
            ],
            'banner_nosotros_url' => 'assets/images/banners-paginas/nosotros.svg',
            'banner_productos_url' => 'assets/images/banners-paginas/productos.svg',
            'banner_clientes_url' => 'assets/images/banners-paginas/clientes.svg',
            'banner_contacto_url' => 'assets/images/banners-paginas/contacto.svg',
            'titulo_nosotros' => 'Tradicion de Pollo Fresco en el mercado Ayaymama',
            'descripcion_nosotros' => 'Somos un equipo familiar que abastece hogares, restaurantes y pollerias con productos frescos y atencion cercana.',
            'mision' => 'Ofrecer productos de confianza con atencion cercana y precios transparentes.',
            'vision' => 'Ser la opcion favorita del mercado Ayaymama por nuestra calidad diaria.',
            'valores' => 'Honestidad, higiene, puntualidad y compromiso.',
            'titulo_productos' => 'Productos frescos y listos para vender',
            'descripcion_productos' => 'Seleccionamos y preparamos cada pedido con cuidado.',
            'titulo_clientes' => 'Clientes que confian en nosotros',
            'descripcion_clientes' => 'Negocios y familias que valoran nuestra calidad y puntualidad.',
            'titulo_contacto' => 'Contactanos en el mercado Ayaymama',
            'descripcion_contacto' => 'Coordinemos tu pedido diario o mayorista. Respondemos rapido por WhatsApp.',
            'descripcion_footer' => 'Productos frescos con atencion diaria y entregas coordinadas.',
            'productos_destacados' => [
                ['nombre' => 'Pollo entero', 'descripcion' => 'Ideal para asados, guisos y negocios.', 'precio' => 'Desde S/ 12.00', 'imagen_url' => 'assets/images/carusel/carousel-2.svg'],
                ['nombre' => 'Pechuga premium', 'descripcion' => 'Corte limpio para porciones saludables.', 'precio' => 'Desde S/ 14.50', 'imagen_url' => 'assets/images/carusel/carousel-1.svg'],
                ['nombre' => 'Pierna y muslo', 'descripcion' => 'Jugosos y rendidores para distintas preparaciones.', 'precio' => 'Desde S/ 11.00', 'imagen_url' => 'assets/images/carusel/carousel-3.svg'],
            ],
            'testimonios' => [
                ['autor' => 'Polleria Don Lucho', 'texto' => 'Siempre encuentro productos frescos y el pedido llega puntual.'],
                ['autor' => 'Restaurante La Parra', 'texto' => 'Buena atencion y precios claros, ideal para mi negocio.'],
                ['autor' => 'Familia Ramos', 'texto' => 'Compramos cada semana y recibimos una excelente atencion.'],
            ],
            'direccion' => 'Mercado Ayaymama - Pollo Fresco',
            'horario' => 'Lunes a Domingo 6:00 - 19:00',
            'telefono' => '+51 965 432 100',
            'whatsapp' => '51965432100',
            'correo' => 'ventas@pollofresco.pe',
            'facebook_url' => '',
            'instagram_url' => '',
            'tiktok_url' => '',
            'mostrar_nosotros' => true,
            'mostrar_contacto' => true,
        ];

        return File::exists($this->ruta())
            ? array_merge($base, json_decode(File::get($this->ruta()), true) ?: [])
            : $base;
    }

    private function ruta(): string
    {
        return storage_path('app/configuracion/pagina-publica.json');
    }
}
