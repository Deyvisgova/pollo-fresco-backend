<?php

use App\Http\Controllers\Api\AuthController;

use App\Http\Controllers\Api\UsuariosController;


use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComprobantePdfController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\ConfiguracionSunatController;
use App\Http\Controllers\Api\DocumentoIdentidadController;
use App\Http\Controllers\Api\EntregaProveedorController;
use App\Http\Controllers\Api\GastoController;
use App\Http\Controllers\Api\OtrosProductosController;
use App\Http\Controllers\Api\OperacionSunatController;
use App\Http\Controllers\Api\PagoProveedorController;
use App\Http\Controllers\Api\PaginaPublicaController;
use App\Http\Controllers\Api\PedidoDeliveryController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\VentaController;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas API
|--------------------------------------------------------------------------
|
| Aquí puedes registrar las rutas API de la aplicación. Estas rutas se
| cargan mediante el RouteServiceProvider y se asignan al middleware "api".
|
*/

/*
|--------------------------------------------------------------------------
| Endpoints de Autenticación
|--------------------------------------------------------------------------
| Estas rutas gestionan registro, inicio de sesión, recuperación de
| contraseñas y manejo de tokens usando Sanctum y el broker de contraseñas.
*/
Route::prefix('auth')->group(function () {
    // Endpoints públicos para registro e inicio de sesión.
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Endpoints públicos para recuperación de contraseña.
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    // Endpoints protegidos que requieren token válido.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Endpoint de Usuario Autenticado
|--------------------------------------------------------------------------
| Esta ruta se conserva por compatibilidad y devuelve el usuario autenticado.
*/
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::get('pagina-publica', [PaginaPublicaController::class, 'mostrar']);
Route::get('pagina-publica/imagenes/{archivo}', [PaginaPublicaController::class, 'mostrarImagen'])
    ->where('archivo', '[A-Za-z0-9._-]+');
Route::get('frontis/{archivo}', [PedidoDeliveryController::class, 'mostrarFotoFrontis'])
    ->where('archivo', '[A-Za-z0-9._-]+');


/*
|--------------------------------------------------------------------------
| Gestión de usuarios
|--------------------------------------------------------------------------
| CRUD completo para administrar usuarios del sistema.
*/

Route::middleware('auth:sanctum')->apiResource('usuarios', UsuariosController::class);
/*
 Endpoints de Proveedores

CRUD de proveedores y registro de entregas de pollos.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('proveedores', ProveedorController::class)
        ->parameters(['proveedores' => 'proveedor'])
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::apiResource('clientes', ClienteController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::get('entregas-proveedor', [EntregaProveedorController::class, 'index']);
    Route::post('entregas-proveedor', [EntregaProveedorController::class, 'store']);
    Route::put('entregas-proveedor/{entregaProveedor}', [EntregaProveedorController::class, 'update']);
    Route::delete('entregas-proveedor/{entregaProveedor}', [EntregaProveedorController::class, 'destroy']);
    Route::get('pagos-proveedor', [PagoProveedorController::class, 'index']);
    Route::get('pagos-proveedor/pdf', [PagoProveedorController::class, 'pdf']);
    Route::post('pagos-proveedor', [PagoProveedorController::class, 'store']);

    Route::get('gastos/resumen', [GastoController::class, 'resumen']);
    Route::get('gastos/categorias', [GastoController::class, 'categorias']);
    Route::post('gastos/categorias', [GastoController::class, 'guardarCategoria']);
    Route::post('gastos', [GastoController::class, 'guardarGasto']);
    Route::post('gastos/capital', [GastoController::class, 'guardarCapital']);
    Route::patch('gastos/capital/{capitalId}/anular', [GastoController::class, 'anularCapital']);
    Route::patch('gastos/{gastoId}/anular', [GastoController::class, 'eliminarGasto']);
    Route::delete('gastos/{gastoId}', [GastoController::class, 'eliminarGasto']);
    Route::put('gastos/venta-pollo-gallina', [GastoController::class, 'guardarVentaPolloGallina']);
    Route::post('gastos/cierre-mensual', [GastoController::class, 'cerrarMes']);
    Route::get('reportes/resumen', [ReporteController::class, 'resumen']);
    Route::get('reportes/pdf', [ReporteController::class, 'pdf']);
    Route::get('reportes/inicio', [ReporteController::class, 'inicio']);

    Route::get('otros-productos/productos', [OtrosProductosController::class, 'productosIndex']);
    Route::post('otros-productos/productos', [OtrosProductosController::class, 'productosStore']);
    Route::put('otros-productos/productos/{productoId}', [OtrosProductosController::class, 'productosUpdate']);
    Route::delete('otros-productos/productos/{productoId}', [OtrosProductosController::class, 'productosDestroy']);
    Route::get('otros-productos/lotes', [OtrosProductosController::class, 'lotesIndex']);
    Route::post('otros-productos/lotes', [OtrosProductosController::class, 'lotesStore']);
    Route::put('otros-productos/lotes/{compraLoteId}', [OtrosProductosController::class, 'lotesUpdate']);
    Route::delete('otros-productos/lotes/{compraLoteId}', [OtrosProductosController::class, 'lotesDestroy']);
    Route::get('otros-productos/ventas-diarias', [OtrosProductosController::class, 'ventasDiariasEstado']);
    Route::put('otros-productos/ventas-diarias', [OtrosProductosController::class, 'ventasDiariasGuardar']);
    Route::post('otros-productos/ventas-diarias/cerrar', [OtrosProductosController::class, 'ventasDiariasCerrar']);
    Route::post('otros-productos/ventas-diarias/reabrir', [OtrosProductosController::class, 'ventasDiariasReabrir']);

    Route::post('configuracion/logo', [ConfiguracionController::class, 'subirLogo']);
    Route::delete('configuracion/logo', [ConfiguracionController::class, 'eliminarLogo']);
    Route::get('configuracion/sunat', [ConfiguracionSunatController::class, 'mostrar']);
    Route::put('configuracion/sunat', [ConfiguracionSunatController::class, 'guardar']);
    Route::post('configuracion/sunat/certificado', [ConfiguracionSunatController::class, 'subirCertificado']);
    Route::put('pagina-publica', [PaginaPublicaController::class, 'guardar']);
    Route::post('pagina-publica/imagen', [PaginaPublicaController::class, 'subirImagen']);
    Route::get('documentos/{tipo}/{numero}', [DocumentoIdentidadController::class, 'mostrar'])
        ->whereIn('tipo', ['dni', 'ruc'])
        ->whereNumber('numero');

    Route::get('ventas', [VentaController::class, 'index']);
    Route::get('ventas/preparar-desde-pedido/{pedido}', [VentaController::class, 'prepararDesdePedido']);
    Route::get('ventas/siguiente-correlativo', [VentaController::class, 'siguienteCorrelativo']);
    Route::post('ventas', [VentaController::class, 'store']);
    Route::post('ventas/{ventaId}/nota-credito', [VentaController::class, 'crearNotaCredito']);
    Route::post('ventas/{ventaId}/nota-debito', [VentaController::class, 'crearNotaDebito']);
    Route::post('ventas/{ventaId}/enviar-sunat', [VentaController::class, 'enviarSunat']);
    Route::get('ventas/{ventaId}/pdf', [ComprobantePdfController::class, 'descargar']);
    Route::get('ventas/{ventaId}/xml', [VentaController::class, 'xml']);
    Route::get('ventas/{ventaId}/cdr', [VentaController::class, 'cdr']);
    Route::get('sunat/resumenes', [OperacionSunatController::class, 'resumenes']);
    Route::post('sunat/resumenes', [OperacionSunatController::class, 'enviarResumen']);
    Route::post('sunat/resumenes/{resumenId}/consultar', [OperacionSunatController::class, 'consultarResumen']);
    Route::get('sunat/bajas', [OperacionSunatController::class, 'bajas']);
    Route::post('sunat/bajas/ventas/{ventaId}', [OperacionSunatController::class, 'enviarBaja']);
    Route::post('sunat/bajas/{bajaId}/consultar', [OperacionSunatController::class, 'consultarBaja']);

    Route::get('pedidos-delivery', [PedidoDeliveryController::class, 'index']);
    Route::post('pedidos-delivery', [PedidoDeliveryController::class, 'store']);
    Route::get('pedidos-delivery/cobros-atrasados', [PedidoDeliveryController::class, 'cobrosAtrasadosDelivery']);
    Route::get('cuentas-por-cobrar', [PedidoDeliveryController::class, 'cuentasPorCobrar']);
    Route::post('cuentas-por-cobrar/clientes/{cliente}/pagos', [PedidoDeliveryController::class, 'registrarPagoCliente']);
    Route::patch('pedidos-delivery/{pedido}/tomar', [PedidoDeliveryController::class, 'tomarPedido']);
    Route::post('pedidos-delivery/{pedido}/pagos', [PedidoDeliveryController::class, 'registrarPago']);
    Route::patch('pedidos-delivery/{pedido}/gestion', [PedidoDeliveryController::class, 'gestionarEstadoPago']);
    Route::post('pedidos-delivery/{pedido}/ubicacion-evidencia', [PedidoDeliveryController::class, 'actualizarUbicacionEvidencia']);
    Route::patch('pedidos-delivery/{pedido}/ubicacion-evidencia', [PedidoDeliveryController::class, 'actualizarUbicacionEvidencia']);
});
