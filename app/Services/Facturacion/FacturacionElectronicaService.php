<?php

namespace App\Services\Facturacion;

use DateTime;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use Greenter\Report\XmlUtils;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FacturacionElectronicaService
{
    public function generarXmlFirmado(int $ventaId): array
    {
        [$venta, $detalles] = $this->obtenerVenta($ventaId);
        $documento = $this->crearDocumento($venta, $detalles);
        $xml = $this->crearSee(false)->getXmlSigned($documento);

        if (! $xml) {
            throw new RuntimeException('No se pudo generar el XML firmado.');
        }

        $ruta = "sunat/xml/{$documento->getName()}.xml";
        Storage::disk('local')->put($ruta, $xml);
        DB::table('ventas')->where('comprobante_venta_id', $ventaId)->update([
            'xml_firmado_ruta' => $ruta,
            'codigo_hash' => $this->obtenerHash($xml),
            'estado_sunat' => in_array($venta->estado_sunat, ['NO_ENVIADO', 'ERROR_ENVIO', 'XML_GENERADO'], true)
                ? 'XML_GENERADO'
                : $venta->estado_sunat,
        ]);

        return ['ruta' => $ruta, 'contenido' => $xml, 'nombre' => $documento->getName()];
    }

    public function enviar(int $ventaId): array
    {
        [$venta, $detalles] = $this->obtenerVenta($ventaId);
        if (! in_array($venta->codigo_tipo_comprobante, ['01', '07', '08'], true)) {
            throw new RuntimeException('Este comprobante debe enviarse mediante resumen diario o no aplica a SUNAT.');
        }
        if (in_array($venta->estado_sunat, ['ACEPTADO', 'ACEPTADO_CON_OBSERVACIONES', 'ANULADO', 'BAJA_EN_PROCESO'], true)) {
            throw new RuntimeException('El comprobante ya fue procesado por SUNAT y no puede reenviarse.');
        }

        $see = $this->crearSee(true);
        $documento = $this->crearDocumento($venta, $detalles);
        $xml = $see->getXmlSigned($documento);
        $rutaXml = "sunat/xml/{$documento->getName()}.xml";
        Storage::disk('local')->put($rutaXml, $xml);

        $resultado = $see->send($documento);
        if (! $resultado) {
            throw new RuntimeException('SUNAT no devolvio una respuesta.');
        }
        if ($resultado->getError()) {
            $error = $resultado->getError();
            $this->actualizarErrorVenta($ventaId, $rutaXml, $error->getCode(), $error->getMessage());
            throw new RuntimeException("SUNAT {$error->getCode()}: {$error->getMessage()}");
        }

        $cdr = $resultado->getCdrResponse();
        $rutaCdr = "sunat/cdr/R-{$documento->getName()}.zip";
        if ($resultado->getCdrZip()) {
            Storage::disk('local')->put($rutaCdr, $resultado->getCdrZip());
        }
        $codigo = (string) ($cdr?->getCode() ?? '');
        $estado = $codigo === '0'
            ? (count($cdr?->getNotes() ?? []) > 0 ? 'ACEPTADO_CON_OBSERVACIONES' : 'ACEPTADO')
            : 'RECHAZADO';

        DB::table('ventas')->where('comprobante_venta_id', $ventaId)->update([
            'estado_sunat' => $estado,
            'respuesta_sunat_codigo' => $codigo,
            'respuesta_sunat_descripcion' => $cdr?->getDescription(),
            'xml_firmado_ruta' => $rutaXml,
            'cdr_ruta' => $resultado->getCdrZip() ? $rutaCdr : null,
            'codigo_hash' => $this->obtenerHash($xml),
            'enviado_sunat_en' => now(),
        ]);

        return [
            'estado_sunat' => $estado,
            'codigo' => $codigo,
            'descripcion' => $cdr?->getDescription(),
            'observaciones' => $cdr?->getNotes() ?? [],
        ];
    }

    public function enviarResumenBoletas(string $fecha, ?int $usuarioId): array
    {
        $boletas = DB::table('ventas')
            ->where('codigo_tipo_comprobante', '03')
            ->whereDate('fecha_emision', $fecha)
            ->whereIn('estado_sunat', ['NO_ENVIADO', 'ERROR_ENVIO', 'XML_GENERADO'])
            ->orderBy('comprobante_venta_id')
            ->get();

        if ($boletas->isEmpty()) {
            throw new RuntimeException('No hay boletas pendientes para la fecha seleccionada.');
        }

        [$resumenId, $documento, $rutaXml] = DB::transaction(function () use ($fecha, $usuarioId, $boletas) {
            $serie = DB::table('comprobante_series')
                ->where('codigo_tipo_comprobante', 'RC')
                ->where('activo', true)
                ->lockForUpdate()
                ->first();
            if (! $serie) {
                throw new RuntimeException('No existe una serie activa para resumenes diarios.');
            }

            $correlativo = ((int) $serie->correlativo_actual) + 1;
            DB::table('comprobante_series')->where('serie_id', $serie->serie_id)->update([
                'correlativo_actual' => $correlativo,
                'actualizado_en' => now(),
            ]);

            $documento = $this->crearResumen($fecha, $correlativo, $boletas);
            $xml = $this->crearSee(false)->getXmlSigned($documento);
            $rutaXml = "sunat/xml/{$documento->getName()}.xml";
            Storage::disk('local')->put($rutaXml, $xml);

            $resumenId = DB::table('sunat_resumenes_diarios')->insertGetId([
                'fecha_documentos' => $fecha,
                'fecha_resumen' => now()->toDateString(),
                'correlativo' => $correlativo,
                'nombre_archivo' => $documento->getName(),
                'estado' => 'ENVIANDO',
                'xml_ruta' => $rutaXml,
                'usuario_id' => $usuarioId,
                'creado_en' => now(),
            ]);

            foreach ($boletas as $boleta) {
                DB::table('sunat_resumen_detalles')->insert([
                    'resumen_id' => $resumenId,
                    'comprobante_venta_id' => $boleta->comprobante_venta_id,
                    'estado_item' => '1',
                    'creado_en' => now(),
                ]);
            }

            return [$resumenId, $documento, $rutaXml];
        });

        try {
            $resultado = $this->crearSee(true)->send($documento);
            if (! $resultado || $resultado->getError()) {
                throw new RuntimeException($resultado?->getError()?->getMessage() ?: 'SUNAT no devolvio ticket.');
            }

            $ticket = $resultado->getTicket();
            DB::table('sunat_resumenes_diarios')->where('resumen_id', $resumenId)->update([
                'ticket' => $ticket,
                'estado' => 'EN_PROCESO',
                'enviado_en' => now(),
                'actualizado_en' => now(),
            ]);
            DB::table('ventas')->whereIn('comprobante_venta_id', $boletas->pluck('comprobante_venta_id'))->update([
                'estado_sunat' => 'EN_RESUMEN',
                'enviado_sunat_en' => now(),
            ]);

            return ['resumen_id' => $resumenId, 'ticket' => $ticket, 'estado' => 'EN_PROCESO'];
        } catch (\Throwable $e) {
            DB::table('sunat_resumenes_diarios')->where('resumen_id', $resumenId)->update([
                'estado' => 'ERROR_ENVIO',
                'respuesta_descripcion' => $e->getMessage(),
                'actualizado_en' => now(),
            ]);
            throw $e;
        }
    }

    public function consultarResumen(int $resumenId): array
    {
        $resumen = DB::table('sunat_resumenes_diarios')->where('resumen_id', $resumenId)->first();
        if (! $resumen || ! $resumen->ticket) {
            throw new RuntimeException('El resumen no tiene un ticket valido.');
        }

        $resultado = $this->crearSee(true)->getStatus($resumen->ticket);
        if ($resultado->getError()) {
            throw new RuntimeException($resultado->getError()->getMessage());
        }

        if ((string) $resultado->getCode() === '98') {
            DB::table('sunat_resumenes_diarios')->where('resumen_id', $resumenId)->update([
                'estado' => 'EN_PROCESO',
                'consultado_en' => now(),
            ]);
            return ['estado' => 'EN_PROCESO', 'codigo' => '98'];
        }

        $cdr = $resultado->getCdrResponse();
        $codigo = (string) ($cdr?->getCode() ?? $resultado->getCode());
        $estado = $codigo === '0' ? 'ACEPTADO' : 'RECHAZADO';
        $rutaCdr = "sunat/cdr/R-{$resumen->nombre_archivo}.zip";
        if ($resultado->getCdrZip()) {
            Storage::disk('local')->put($rutaCdr, $resultado->getCdrZip());
        }

        DB::table('sunat_resumenes_diarios')->where('resumen_id', $resumenId)->update([
            'estado' => $estado,
            'respuesta_codigo' => $codigo,
            'respuesta_descripcion' => $cdr?->getDescription(),
            'cdr_ruta' => $resultado->getCdrZip() ? $rutaCdr : null,
            'consultado_en' => now(),
            'actualizado_en' => now(),
        ]);

        $ventasIds = DB::table('sunat_resumen_detalles')->where('resumen_id', $resumenId)
            ->pluck('comprobante_venta_id');
        DB::table('ventas')->whereIn('comprobante_venta_id', $ventasIds)->update([
            'estado_sunat' => $estado,
            'respuesta_sunat_codigo' => $codigo,
            'respuesta_sunat_descripcion' => $cdr?->getDescription(),
            'cdr_ruta' => $resultado->getCdrZip() ? $rutaCdr : null,
        ]);

        return ['estado' => $estado, 'codigo' => $codigo, 'descripcion' => $cdr?->getDescription()];
    }

    public function listarResumenes(): array
    {
        return DB::table('sunat_resumenes_diarios')
            ->select('sunat_resumenes_diarios.*')
            ->selectSub(function ($query) {
                $query->from('sunat_resumen_detalles')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('sunat_resumen_detalles.resumen_id', 'sunat_resumenes_diarios.resumen_id');
            }, 'cantidad_boletas')
            ->orderByDesc('resumen_id')
            ->limit(50)
            ->get()
            ->all();
    }

    public function enviarComunicacionBaja(int $ventaId, string $motivo, ?int $usuarioId): array
    {
        $venta = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();
        if (! $venta || $venta->codigo_tipo_comprobante !== '01') {
            throw new RuntimeException('Solo se puede comunicar la baja de una factura.');
        }
        if (! in_array($venta->estado_sunat, ['ACEPTADO', 'ACEPTADO_CON_OBSERVACIONES'], true)) {
            throw new RuntimeException('La factura debe estar aceptada por SUNAT antes de comunicar su baja.');
        }
        if (DB::table('sunat_comunicaciones_baja')->where('comprobante_venta_id', $ventaId)
            ->whereNotIn('estado', ['RECHAZADO', 'ERROR_ENVIO'])->exists()) {
            throw new RuntimeException('Esta factura ya tiene una comunicacion de baja vigente.');
        }

        [$bajaId, $documento] = DB::transaction(function () use ($venta, $ventaId, $motivo, $usuarioId) {
            $serie = DB::table('comprobante_series')->where('codigo_tipo_comprobante', 'RA')
                ->where('activo', true)->lockForUpdate()->first();
            if (! $serie) {
                throw new RuntimeException('No existe una serie activa para comunicaciones de baja.');
            }
            $correlativo = ((int) $serie->correlativo_actual) + 1;
            DB::table('comprobante_series')->where('serie_id', $serie->serie_id)->update([
                'correlativo_actual' => $correlativo,
                'actualizado_en' => now(),
            ]);

            $documento = (new Voided())
                ->setFecGeneracion(new DateTime($venta->fecha_emision))
                ->setFecComunicacion(new DateTime())
                ->setCorrelativo(str_pad((string) $correlativo, 3, '0', STR_PAD_LEFT))
                ->setCompany($this->crearEmpresa())
                ->setDetails([
                    (new VoidedDetail())
                        ->setTipoDoc('01')
                        ->setSerie($venta->serie)
                        ->setCorrelativo(ltrim($venta->numero, '0') ?: '0')
                        ->setDesMotivoBaja($motivo),
                ]);
            $xml = $this->crearSee(false)->getXmlSigned($documento);
            $rutaXml = "sunat/xml/{$documento->getName()}.xml";
            Storage::disk('local')->put($rutaXml, $xml);

            $bajaId = DB::table('sunat_comunicaciones_baja')->insertGetId([
                'comprobante_venta_id' => $ventaId,
                'fecha_documento' => $venta->fecha_emision,
                'fecha_comunicacion' => now()->toDateString(),
                'correlativo' => $correlativo,
                'motivo' => $motivo,
                'nombre_archivo' => $documento->getName(),
                'estado' => 'ENVIANDO',
                'xml_ruta' => $rutaXml,
                'usuario_id' => $usuarioId,
                'creado_en' => now(),
            ]);

            return [$bajaId, $documento];
        });

        try {
            $resultado = $this->crearSee(true)->send($documento);
            if (! $resultado || $resultado->getError()) {
                throw new RuntimeException($resultado?->getError()?->getMessage() ?: 'SUNAT no devolvio ticket.');
            }
            $ticket = $resultado->getTicket();
            DB::table('sunat_comunicaciones_baja')->where('comunicacion_baja_id', $bajaId)->update([
                'ticket' => $ticket, 'estado' => 'EN_PROCESO', 'enviado_en' => now(), 'actualizado_en' => now(),
            ]);
            DB::table('ventas')->where('comprobante_venta_id', $ventaId)->update(['estado_sunat' => 'BAJA_EN_PROCESO']);

            return ['comunicacion_baja_id' => $bajaId, 'ticket' => $ticket, 'estado' => 'EN_PROCESO'];
        } catch (\Throwable $e) {
            DB::table('sunat_comunicaciones_baja')->where('comunicacion_baja_id', $bajaId)->update([
                'estado' => 'ERROR_ENVIO', 'respuesta_descripcion' => $e->getMessage(), 'actualizado_en' => now(),
            ]);
            throw $e;
        }
    }

    public function consultarComunicacionBaja(int $bajaId): array
    {
        $baja = DB::table('sunat_comunicaciones_baja')->where('comunicacion_baja_id', $bajaId)->first();
        if (! $baja || ! $baja->ticket) {
            throw new RuntimeException('La comunicacion de baja no tiene un ticket valido.');
        }

        $resultado = $this->crearSee(true)->getStatus($baja->ticket);
        if ($resultado->getError()) {
            throw new RuntimeException($resultado->getError()->getMessage());
        }
        if ((string) $resultado->getCode() === '98') {
            DB::table('sunat_comunicaciones_baja')->where('comunicacion_baja_id', $bajaId)
                ->update(['estado' => 'EN_PROCESO', 'consultado_en' => now()]);
            return ['estado' => 'EN_PROCESO', 'codigo' => '98'];
        }

        $cdr = $resultado->getCdrResponse();
        $codigo = (string) ($cdr?->getCode() ?? $resultado->getCode());
        $estado = $codigo === '0' ? 'ACEPTADO' : 'RECHAZADO';
        $rutaCdr = "sunat/cdr/R-{$baja->nombre_archivo}.zip";
        if ($resultado->getCdrZip()) {
            Storage::disk('local')->put($rutaCdr, $resultado->getCdrZip());
        }
        DB::table('sunat_comunicaciones_baja')->where('comunicacion_baja_id', $bajaId)->update([
            'estado' => $estado,
            'respuesta_codigo' => $codigo,
            'respuesta_descripcion' => $cdr?->getDescription(),
            'cdr_ruta' => $resultado->getCdrZip() ? $rutaCdr : null,
            'consultado_en' => now(),
            'actualizado_en' => now(),
        ]);
        DB::table('ventas')->where('comprobante_venta_id', $baja->comprobante_venta_id)->update([
            'estado_sunat' => $estado === 'ACEPTADO' ? 'ANULADO' : 'ACEPTADO',
            'respuesta_sunat_codigo' => $codigo,
            'respuesta_sunat_descripcion' => $cdr?->getDescription(),
        ]);

        return ['estado' => $estado, 'codigo' => $codigo, 'descripcion' => $cdr?->getDescription()];
    }

    public function listarComunicacionesBaja(): array
    {
        return DB::table('sunat_comunicaciones_baja as b')
            ->join('ventas as v', 'v.comprobante_venta_id', '=', 'b.comprobante_venta_id')
            ->select('b.*', 'v.serie', 'v.numero', 'v.cliente_nombre', 'v.total')
            ->orderByDesc('b.comunicacion_baja_id')->limit(50)->get()->all();
    }

    private function crearSee(bool $requiereCredenciales): See
    {
        $configuracion = DB::table('configuracion_sunat')->orderByDesc('configuracion_sunat_id')->first();
        if (! $configuracion || ! $configuracion->activo) {
            throw new RuntimeException('La facturacion electronica SUNAT no esta activa.');
        }
        if (! $configuracion->certificado_ruta || ! Storage::disk('local')->exists($configuracion->certificado_ruta)) {
            throw new RuntimeException('Falta configurar el certificado digital.');
        }

        $see = new See();
        $see->setCertificate(Storage::disk('local')->get($configuracion->certificado_ruta));
        $see->setService($configuracion->ambiente === 'produccion'
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA);

        if ($requiereCredenciales) {
            if (! $configuracion->usuario_sol || ! $configuracion->clave_sol_encriptada) {
                throw new RuntimeException('Faltan las credenciales SOL.');
            }
            $see->setClaveSOL(
                $configuracion->ruc,
                $configuracion->usuario_sol,
                Crypt::decryptString($configuracion->clave_sol_encriptada)
            );
        }

        return $see;
    }

    private function crearDocumento(object $venta, iterable $detalles): DocumentInterface
    {
        $cliente = (new Client())
            ->setTipoDoc($venta->cliente_tipo_documento === 'ruc' ? '6' : ($venta->cliente_documento ? '1' : '0'))
            ->setNumDoc($venta->cliente_documento ?: '00000000')
            ->setRznSocial($venta->cliente_nombre ?: 'CLIENTE VARIOS')
            ->setAddress((new Address())->setDireccion($venta->cliente_direccion ?: '-'));

        $items = [];
        foreach ($detalles as $indice => $detalle) {
            $items[] = (new SaleDetail())
                ->setCodProducto((string) ($indice + 1))
                ->setUnidad($detalle->codigo_unidad ?: 'NIU')
                ->setCantidad((float) $detalle->cantidad)
                ->setDescripcion($detalle->descripcion)
                ->setMtoBaseIgv((float) $detalle->valor_venta)
                ->setPorcentajeIgv((float) ($detalle->igv > 0 ? 18 : 0))
                ->setIgv((float) $detalle->igv)
                ->setTipAfeIgv($detalle->tipo_afectacion_igv ?: '20')
                ->setTotalImpuestos((float) $detalle->total_impuestos)
                ->setMtoValorVenta((float) $detalle->valor_venta)
                ->setMtoValorUnitario((float) $detalle->valor_unitario)
                ->setMtoPrecioUnitario((float) $detalle->precio_unitario);
        }

        $documento = in_array($venta->codigo_tipo_comprobante, ['07', '08'], true)
            ? (new Note())
                ->setTipDocAfectado($this->tipoDocumentoReferencia($venta))
                ->setNumDocfectado("{$venta->referencia_serie}-{$venta->referencia_numero}")
                ->setCodMotivo($venta->nota_motivo_codigo ?: '01')
                ->setDesMotivo($venta->referencia_motivo ?: 'ANULACION DE LA OPERACION')
            : new Invoice();

        return $documento
            ->setCompany($this->crearEmpresa())
            ->setTipoDoc($venta->codigo_tipo_comprobante)
            ->setSerie($venta->serie)
            ->setCorrelativo(ltrim($venta->numero, '0') ?: '0')
            ->setFechaEmision(new DateTime($venta->fecha_emision))
            ->setFormaPago(new FormaPagoContado())
            ->setTipoMoneda($venta->moneda)
            ->setClient($cliente)
            ->setMtoOperGravadas((float) $venta->operacion_gravada)
            ->setMtoOperExoneradas((float) $venta->operacion_exonerada)
            ->setMtoOperInafectas((float) $venta->operacion_inafecta)
            ->setMtoIGV((float) $venta->igv)
            ->setTotalImpuestos((float) $venta->total_impuestos)
            ->setValorVenta((float) $venta->subtotal)
            ->setSubTotal((float) $venta->total)
            ->setMtoImpVenta((float) $venta->total)
            ->setDetails($items);
    }

    private function crearResumen(string $fecha, int $correlativo, iterable $boletas): Summary
    {
        $detalles = [];
        foreach ($boletas as $boleta) {
            $detalles[] = (new SummaryDetail())
                ->setTipoDoc('03')
                ->setSerieNro("{$boleta->serie}-" . (ltrim($boleta->numero, '0') ?: '0'))
                ->setEstado('1')
                ->setClienteTipo($boleta->cliente_tipo_documento === 'ruc' ? '6' : ($boleta->cliente_documento ? '1' : '0'))
                ->setClienteNro($boleta->cliente_documento ?: '00000000')
                ->setTotal((float) $boleta->total)
                ->setMtoOperGravadas((float) $boleta->operacion_gravada)
                ->setMtoOperExoneradas((float) $boleta->operacion_exonerada)
                ->setMtoOperInafectas((float) $boleta->operacion_inafecta)
                ->setMtoIGV((float) $boleta->igv)
                ->setPorcentajeIgv((float) ($boleta->igv > 0 ? 18 : 0));
        }

        return (new Summary())
            ->setFecGeneracion(new DateTime($fecha))
            ->setFecResumen(new DateTime())
            ->setCorrelativo(str_pad((string) $correlativo, 3, '0', STR_PAD_LEFT))
            ->setCompany($this->crearEmpresa())
            ->setDetails($detalles);
    }

    private function crearEmpresa(): Company
    {
        $configuracion = DB::table('configuracion_sunat')->orderByDesc('configuracion_sunat_id')->first();
        if (! $configuracion) {
            throw new RuntimeException('Falta configurar los datos del emisor SUNAT.');
        }

        return (new Company())
            ->setRuc($configuracion->ruc)
            ->setRazonSocial($configuracion->razon_social)
            ->setNombreComercial($configuracion->nombre_comercial ?: $configuracion->razon_social)
            ->setAddress((new Address())
                ->setUbigueo($configuracion->ubigeo ?: '000000')
                ->setDepartamento($configuracion->departamento ?: '-')
                ->setProvincia($configuracion->provincia ?: '-')
                ->setDistrito($configuracion->distrito ?: '-')
                ->setDireccion($configuracion->direccion_fiscal)
                ->setCodLocal('0000'))
            ->setEmail($configuracion->correo);
    }

    private function tipoDocumentoReferencia(object $venta): string
    {
        $referencia = DB::table('ventas')->where('comprobante_venta_id', $venta->venta_referencia_id)->first();
        return $referencia?->codigo_tipo_comprobante ?: '01';
    }

    private function obtenerVenta(int $ventaId): array
    {
        $venta = DB::table('ventas')->where('comprobante_venta_id', $ventaId)->first();
        if (! $venta) {
            throw new RuntimeException('Venta no encontrada.');
        }

        $detalles = DB::table('venta_detalle')
            ->where('comprobante_venta_id', $ventaId)
            ->orderBy('comprobante_venta_detalle_id')
            ->get();

        return [$venta, $detalles];
    }

    private function actualizarErrorVenta(int $ventaId, string $rutaXml, ?string $codigo, ?string $mensaje): void
    {
        DB::table('ventas')->where('comprobante_venta_id', $ventaId)->update([
            'estado_sunat' => 'ERROR_ENVIO',
            'respuesta_sunat_codigo' => $codigo,
            'respuesta_sunat_descripcion' => $mensaje,
            'xml_firmado_ruta' => $rutaXml,
            'codigo_hash' => Storage::disk('local')->exists($rutaXml)
                ? $this->obtenerHash(Storage::disk('local')->get($rutaXml))
                : null,
            'enviado_sunat_en' => now(),
        ]);
    }

    private function obtenerHash(string $xml): string
    {
        return (string) (new XmlUtils())->getHashSign($xml);
    }
}
