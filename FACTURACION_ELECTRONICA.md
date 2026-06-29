# Facturacion electronica - Pollo Fresco

## Implementado

- Configuracion privada del emisor SUNAT, credenciales SOL y certificado PEM.
- Credenciales cifradas y certificado almacenado fuera de la carpeta publica.
- Consulta DNI/RUC desde el backend; el token no se expone en Angular.
- Series y correlativos administrados por el backend con bloqueo transaccional.
- Validacion de factura con RUC y boleta con DNI.
- Recalculo de cantidades, lineas, total y vuelto en el backend.
- Generacion de XML UBL 2.1 firmado mediante Greenter.
- Envio directo de facturas, notas de credito y notas de debito a SUNAT.
- Resumen diario de boletas con consulta de ticket.
- Comunicacion de baja de facturas con consulta de ticket.
- PDF tributario A4, ticket 80 mm y ticket 57 mm con QR y hash.
- Descarga del XML firmado y CDR almacenado.
- Persistencia de XML, CDR, codigo, descripcion y estado SUNAT.

## Pendiente antes de produccion

1. Clasificacion tributaria:
   - Confirmar con el contador si cada producto es gravado, exonerado o inafecto.
   - Actualmente las ventas se registran como exoneradas, catalogo SUNAT 20.
   - No activar produccion hasta confirmar esta clasificacion.

2. Operacion:
   - Crear una cola automatica de reintentos para caidas de SUNAT.
   - Alertar comprobantes `NO_ENVIADO`, `ERROR_ENVIO` o `RECHAZADO`.
   - Respaldar XML y CDR durante el periodo legal aplicable.
   - Implementar notas de credito parciales cuando el negocio las necesite.
   - Implementar bajas de boletas mediante resumen diario.

## Salida a produccion

1. Completar Configuracion > Facturacion electronica SUNAT.
2. Mantener el ambiente `beta`.
3. Cargar un certificado PEM que incluya clave privada y certificado publico.
4. Emitir facturas, boletas, notas de credito, notas de debito y bajas de prueba; revisar los CDR.
5. Validar impuestos y representacion impresa con el contador.
6. Recien entonces cambiar el ambiente a `produccion`.

## Estados usados

- `NO_ENVIADO`: registrado localmente, pendiente de envio.
- `XML_GENERADO`: XML UBL firmado y guardado.
- `ACEPTADO`: SUNAT acepto el comprobante.
- `ACEPTADO_CON_OBSERVACIONES`: SUNAT acepto con observaciones.
- `RECHAZADO`: SUNAT rechazo el comprobante; debe corregirse.
- `ERROR_ENVIO`: hubo un error de comunicacion; puede reintentarse.
- `EN_RESUMEN`: boleta enviada dentro de un resumen diario.
- `BAJA_EN_PROCESO`: comunicacion de baja enviada y pendiente de respuesta.
- `ANULADO`: SUNAT acepto la comunicacion de baja.
- `NO_APLICA`: documento interno que no se envia a SUNAT.
