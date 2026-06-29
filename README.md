<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Sobre Laravel

Laravel es un framework web con una sintaxis expresiva y elegante. Creemos que el desarrollo debe ser una experiencia agradable y creativa. Laravel simplifica tareas comunes en proyectos web, como:

- [Motor de rutas simple y rápido](https://laravel.com/docs/routing).
- [Contenedor potente de inyección de dependencias](https://laravel.com/docs/container).
- Múltiples backends para [sesiones](https://laravel.com/docs/session) y [cache](https://laravel.com/docs/cache).
- [ORM de base de datos expresivo e intuitivo](https://laravel.com/docs/eloquent).
- [Migraciones de esquema](https://laravel.com/docs/migrations) independientes de la base de datos.
- [Procesamiento robusto de trabajos en segundo plano](https://laravel.com/docs/queues).
- [Difusión de eventos en tiempo real](https://laravel.com/docs/broadcasting).

Laravel es accesible, poderoso y proporciona herramientas para aplicaciones grandes y robustas.

## Configuración de autenticación y base de datos

Laravel ya incluye las piezas necesarias para autenticación segura, cifrado de contraseñas, recuperación de contraseñas por correo y protección de endpoints API. La conexión a la base de datos se configura en `.env`, y Laravel la toma automáticamente desde `config/database.php`.

### Conexión a la base de datos

Configura los datos de conexión dentro de tu archivo `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pollo_fresco
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password
```

### Correo para recuperación de contraseñas

Laravel usa el broker de contraseñas, que envía un email con el token de recuperación. Ajusta tu SMTP en `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.tu-proveedor.com
MAIL_PORT=587
MAIL_USERNAME=usuario_correo
MAIL_PASSWORD=clave_correo
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@pollofresco.com
MAIL_FROM_NAME="Pollo Fresco"
```

### Roles disponibles

El sistema contempla tres roles: `admin`, `manager` y `cashier`. Esta lista se usa para validar registros en los endpoints de autenticación.

## Endpoints API de autenticación

Todos los endpoints están bajo el prefijo `/api/auth` y están protegidos con Sanctum donde aplica:

| Método | Endpoint | Descripción |
| --- | --- | --- |
| POST | `/api/auth/register` | Registra usuarios y retorna token. |
| POST | `/api/auth/login` | Inicia sesión y retorna token. |
| POST | `/api/auth/forgot-password` | Envía link de recuperación. |
| POST | `/api/auth/reset-password` | Restablece la contraseña con token. |
| GET | `/api/auth/me` | Devuelve el usuario autenticado. |
| POST | `/api/auth/logout` | Revoca el token actual. |

> Asegúrate de enviar el token en `Authorization: Bearer <token>` para las rutas protegidas.

## Aprender Laravel

Laravel tiene la [documentación](https://laravel.com/docs) y la librería de tutoriales en video más completa entre los frameworks modernos, facilitando el inicio con el framework.

También puedes probar el [Laravel Bootcamp](https://bootcamp.laravel.com), donde se guía la construcción de una aplicación moderna desde cero.

Si no quieres leer, [Laracasts](https://laracasts.com) puede ayudar. Laracasts contiene miles de tutoriales sobre Laravel, PHP moderno, pruebas unitarias y JavaScript.

## Patrocinadores de Laravel

Extendemos nuestro agradecimiento a los patrocinadores que financian el desarrollo de Laravel. Si quieres ser patrocinador, visita el [programa de socios de Laravel](https://partners.laravel.com).

### Socios premium

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contribuir

Gracias por considerar contribuir al framework Laravel. La guía de contribución está en la [documentación de Laravel](https://laravel.com/docs/contributions).

## Código de conducta

Para asegurar que la comunidad de Laravel sea acogedora, revisa y respeta el [Código de conducta](https://laravel.com/docs/contributions#code-of-conduct).

## Vulnerabilidades de seguridad

Si descubres una vulnerabilidad en Laravel, envía un correo a Taylor Otwell vía [taylor@laravel.com](mailto:taylor@laravel.com). Todas las vulnerabilidades se atienden con rapidez.

## Licencia

Laravel es software de código abierto licenciado bajo la [licencia MIT](https://opensource.org/licenses/MIT).
