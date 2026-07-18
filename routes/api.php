<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComprobantePdfController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\ConfiguracionSunatController;
use App\Http\Controllers\Api\DocumentoIdentidadController;
use App\Http\Controllers\Api\EntregaProveedorController;
use App\Http\Controllers\Api\GastoController;
use App\Http\Controllers\Api\MantenimientoController;
use App\Http\Controllers\Api\OperacionSunatController;
use App\Http\Controllers\Api\OtrosProductosController;
use App\Http\Controllers\Api\PagoProveedorController;
use App\Http\Controllers\Api\PaginaPublicaController;
use App\Http\Controllers\Api\PedidoDeliveryController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\UsuariosController;
use App\Http\Controllers\Api\VentaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/select-role', [AuthController::class, 'selectRole'])->middleware('throttle:10,1');
    Route::post('/email-code/verify', [AuthController::class, 'verifyEmailCode'])->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// El sitio publico solo expone contenido de lectura.
Route::get('pagina-publica', [PaginaPublicaController::class, 'mostrar']);
Route::get('pagina-publica/imagenes/{archivo}', [PaginaPublicaController::class, 'mostrarImagen'])
    ->where('archivo', '[A-Za-z0-9._-]+');
Route::get('configuracion/empresa', [ConfiguracionController::class, 'mostrarEmpresa']);
Route::get('frontis/{archivo}', [PedidoDeliveryController::class, 'mostrarFotoFrontis'])
    ->where('archivo', '[A-Za-z0-9._-]+');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    // La consulta de pedidos la usan vendedor, administrador y delivery.
    Route::middleware('role:admin,vendor,delivery')->group(function () {
        Route::get('pedidos-delivery', [PedidoDeliveryController::class, 'index']);
    });

    // Lecturas y registros operativos permitidos al vendedor.
    Route::middleware('role:admin,vendor')->group(function () {
        Route::get('proveedores', [ProveedorController::class, 'index']);
        Route::get('proveedores/{proveedor}', [ProveedorController::class, 'show']);
        Route::get('clientes', [ClienteController::class, 'index']);
        Route::post('clientes', [ClienteController::class, 'store']);
        Route::get('clientes/{cliente}', [ClienteController::class, 'show']);
        Route::get('entregas-proveedor', [EntregaProveedorController::class, 'index']);
        Route::post('entregas-proveedor', [EntregaProveedorController::class, 'store']);
        Route::get('otros-productos/productos', [OtrosProductosController::class, 'productosIndex']);
        Route::get('otros-productos/ventas-diarias', [OtrosProductosController::class, 'ventasDiariasEstado']);
        Route::put('otros-productos/ventas-diarias', [OtrosProductosController::class, 'ventasDiariasGuardar']);
        Route::get('documentos/{tipo}/{numero}', [DocumentoIdentidadController::class, 'mostrar'])
            ->whereIn('tipo', ['dni', 'ruc'])
            ->whereNumber('numero');

        Route::get('ventas/preparar-desde-pedido/{pedido}', [VentaController::class, 'prepararDesdePedido']);
        Route::get('ventas/siguiente-correlativo', [VentaController::class, 'siguienteCorrelativo']);
        Route::post('ventas', [VentaController::class, 'store']);
        Route::get('ventas/{ventaId}/pdf', [ComprobantePdfController::class, 'descargar']);

        Route::post('pedidos-delivery', [PedidoDeliveryController::class, 'store']);
    });

    // Operaciones exclusivas del repartidor y del administrador.
    Route::middleware('role:admin,delivery')->group(function () {
        Route::get('pedidos-delivery/cobros-atrasados', [PedidoDeliveryController::class, 'cobrosAtrasadosDelivery']);
        Route::get('cuentas-por-cobrar', [PedidoDeliveryController::class, 'cuentasPorCobrar']);
        Route::post('cuentas-por-cobrar/clientes/{cliente}/pagos', [PedidoDeliveryController::class, 'registrarPagoCliente']);
        Route::patch('pedidos-delivery/{pedido}/tomar', [PedidoDeliveryController::class, 'tomarPedido']);
        Route::post('pedidos-delivery/{pedido}/pagos', [PedidoDeliveryController::class, 'registrarPago']);
        Route::patch('pedidos-delivery/{pedido}/gestion', [PedidoDeliveryController::class, 'gestionarEstadoPago']);
        Route::post('pedidos-delivery/{pedido}/ubicacion-evidencia', [PedidoDeliveryController::class, 'actualizarUbicacionEvidencia']);
        Route::patch('pedidos-delivery/{pedido}/ubicacion-evidencia', [PedidoDeliveryController::class, 'actualizarUbicacionEvidencia']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('usuarios', UsuariosController::class);

        Route::get('mantenimiento', [MantenimientoController::class, 'index']);
        Route::post('mantenimiento/respaldos', [MantenimientoController::class, 'crearRespaldo']);
        Route::get('mantenimiento/respaldos/{archivo}/descargar', [MantenimientoController::class, 'descargar']);
        Route::post('mantenimiento/respaldos/{archivo}/restaurar', [MantenimientoController::class, 'restaurar'])->middleware('throttle:3,1');
        Route::post('mantenimiento/reiniciar', [MantenimientoController::class, 'reiniciar'])->middleware('throttle:3,1');

        Route::post('proveedores', [ProveedorController::class, 'store']);
        Route::put('proveedores/{proveedor}', [ProveedorController::class, 'update']);
        Route::patch('proveedores/{proveedor}', [ProveedorController::class, 'update']);
        Route::delete('proveedores/{proveedor}', [ProveedorController::class, 'destroy']);
        Route::put('clientes/{cliente}', [ClienteController::class, 'update']);
        Route::patch('clientes/{cliente}', [ClienteController::class, 'update']);
        Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy']);
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

        Route::post('otros-productos/productos', [OtrosProductosController::class, 'productosStore']);
        Route::put('otros-productos/productos/{productoId}', [OtrosProductosController::class, 'productosUpdate']);
        Route::delete('otros-productos/productos/{productoId}', [OtrosProductosController::class, 'productosDestroy']);
        Route::get('otros-productos/lotes', [OtrosProductosController::class, 'lotesIndex']);
        Route::post('otros-productos/lotes', [OtrosProductosController::class, 'lotesStore']);
        Route::put('otros-productos/lotes/{compraLoteId}', [OtrosProductosController::class, 'lotesUpdate']);
        Route::delete('otros-productos/lotes/{compraLoteId}', [OtrosProductosController::class, 'lotesDestroy']);
        Route::post('otros-productos/ventas-diarias/cerrar', [OtrosProductosController::class, 'ventasDiariasCerrar']);
        Route::post('otros-productos/ventas-diarias/reabrir', [OtrosProductosController::class, 'ventasDiariasReabrir']);

        Route::post('configuracion/logo', [ConfiguracionController::class, 'subirLogo']);
        Route::delete('configuracion/logo', [ConfiguracionController::class, 'eliminarLogo']);
        Route::put('configuracion/empresa', [ConfiguracionController::class, 'guardarEmpresa']);
        Route::get('configuracion/sunat', [ConfiguracionSunatController::class, 'mostrar']);
        Route::put('configuracion/sunat', [ConfiguracionSunatController::class, 'guardar']);
        Route::post('configuracion/sunat/certificado', [ConfiguracionSunatController::class, 'subirCertificado']);
        Route::put('pagina-publica', [PaginaPublicaController::class, 'guardar']);
        Route::post('pagina-publica/imagen', [PaginaPublicaController::class, 'subirImagen']);

        Route::get('ventas', [VentaController::class, 'index']);
        Route::post('ventas/{ventaId}/nota-credito', [VentaController::class, 'crearNotaCredito']);
        Route::post('ventas/{ventaId}/nota-debito', [VentaController::class, 'crearNotaDebito']);
        Route::post('ventas/{ventaId}/enviar-sunat', [VentaController::class, 'enviarSunat']);
        Route::get('ventas/{ventaId}/xml', [VentaController::class, 'xml']);
        Route::get('ventas/{ventaId}/cdr', [VentaController::class, 'cdr']);
        Route::get('sunat/resumenes', [OperacionSunatController::class, 'resumenes']);
        Route::post('sunat/resumenes', [OperacionSunatController::class, 'enviarResumen']);
        Route::post('sunat/resumenes/{resumenId}/consultar', [OperacionSunatController::class, 'consultarResumen']);
        Route::get('sunat/bajas', [OperacionSunatController::class, 'bajas']);
        Route::post('sunat/bajas/ventas/{ventaId}', [OperacionSunatController::class, 'enviarBaja']);
        Route::post('sunat/bajas/{bajaId}/consultar', [OperacionSunatController::class, 'consultarBaja']);
    });
});
