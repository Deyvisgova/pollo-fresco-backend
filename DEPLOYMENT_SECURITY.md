# Despliegue seguro

## Requisitos obligatorios

1. Publicar Laravel con el `DocumentRoot` apuntando solamente a `public/`.
2. Servir frontend y API exclusivamente por HTTPS.
3. Usar el frontend y `/api` bajo el mismo dominio mediante proxy inverso.
4. Copiar `.env.production.example` a `.env` en el servidor y completar secretos fuera de Git.
5. Crear un usuario MySQL exclusivo sin permisos de administracion global.
6. Mantener `APP_DEBUG=false` y `APP_ENV=production`.
7. No ejecutar `ng serve` ni `php artisan serve` en produccion.

## Comandos de despliegue

```bash
composer install --no-dev --classmap-authoritative
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

Publicar el contenido de `dist/front-pollo-fresco/browser` generado por `npm run build`.

## Tareas programadas

Configurar cada minuto el programador de Laravel:

```cron
* * * * * php /ruta/backend/artisan schedule:run > /dev/null 2>&1
```

El programador elimina tokens vencidos y genera respaldos diarios, semanales y mensuales.

## Respaldos

- Configurar `BACKUP_OFFSITE_DISK=s3` o un disco remoto equivalente.
- Usar credenciales diferentes a las del servidor web.
- Activar versionado o retencion inmutable en el proveedor.
- Probar una restauracion al menos una vez al mes.

## Operacion

- El primer acceso como administrador exige configurar un segundo factor TOTP.
- Guardar los codigos de recuperacion fuera del servidor.
- No compartir cuentas entre trabajadores.
- Revocar y reemplazar inmediatamente cualquier secreto expuesto.
- Revisar periodicamente `auditorias_seguridad` y los logs del servidor.
