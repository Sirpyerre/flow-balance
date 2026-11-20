## Why

Flow Balance solo corre en desarrollo: `DatabaseConfig` rechaza cualquier `APP_ENV` distinto de
`development` y `testing`, y el esquema vive en PostgreSQL. El destino de producción es el VPS
que ya aloja el blog Drupal 11 de monoforms.com: Ubuntu 24.04, 1 vCPU, 961 MB de RAM con swap
en uso, PHP 8.3 (FPM), Nginx 1.24 y MariaDB 10.11 escuchando solo en `127.0.0.1`. No hay memoria
para un segundo motor de base de datos, y la app exige PHP 8.4. Hay que adaptar la app al
servidor, no al revés, y publicarla con un pipeline que suba únicamente lo necesario para correr.

## What Changes

- **BREAKING** Portar la capa de datos de PostgreSQL a MariaDB 10.11: consultas SQL, migraciones
  Phinx, bloqueo de idempotencia de cobros, tipos de fecha y colación. Las bases de datos
  existentes de PostgreSQL no se migran; el producto aún no tiene datos que conservar.
- Bajar el piso de PHP a 8.3 (`~8.3.0 || ~8.4.0`), regenerar `composer.lock` con
  `config.platform.php` en 8.3 y reemplazar `ext-pdo_pgsql` por `ext-pdo_mysql`.
- Aceptar `APP_ENV=production` en `DatabaseConfig` y Phinx, sin relajar la separación entre la
  base de pruebas y la de desarrollo.
- Local y CI usan `mariadb:10.11` en `compose.yaml` para tener paridad con producción.
- Definir la configuración del servidor como archivos versionados bajo `deploy/`: vhost de Nginx
  propio para un subdominio de monoforms.com, pool PHP-FPM propio (`ondemand`, 2 procesos),
  y los encabezados `Cache-Control` privados que hoy solo existen en el servidor de desarrollo
  de Vite (`frontend/astro.config.mjs`).
- Pipeline de GitHub Actions: compila `frontend/dist/` y `vendor/` (`--no-dev`) en el runner,
  sube solo una lista blanca de archivos, publica en releases atómicos con rollback y respalda
  la base antes de cada migración. Nada de `docs/`, `specs/`, `openspec/`, `tests/` ni
  `frontend/src/`.
- Secretos de la aplicación (`APP_SECRET`, contraseña de base de datos) viven solo en el
  servidor. GitHub guarda únicamente las credenciales de despliegue.
- Script de verificación de solo lectura que se corre por SSH antes del primer despliegue.

- Agregar `GET /api/health` (sin sesión, sin caché) para comprobar el despliegue. Es la única
  ruta de API nueva.

Fuera de alcance: cambios de UI, rutas de API existentes, nombres accesibles, lógica de negocio y
cálculo monetario; migrar datos de desarrollo; Redis, HTTP/3 y Brotli; mover o tocar la base `drupal`;
elegir el subdominio (queda como variable `APP_ORIGIN`).

## Capabilities

### New Capabilities

- `persistencia-mariadb`: la capa de datos funciona sobre MariaDB 10.11 con las mismas garantías
  de cuenta, dinero, idempotencia y trazabilidad.
- `runtime-php83`: la aplicación y sus dependencias resuelven y corren en PHP 8.3 y 8.4.
- `configuracion-produccion`: entorno `production`, origen público y secretos tomados del
  entorno del servidor, con fallo seguro si faltan.
- `hosting-nginx-fpm`: vhost y pool dedicados, sin interferir con Drupal, con caché privada para
  las vistas autenticadas.
- `pipeline-despliegue`: construcción, lista blanca de archivos, releases atómicos, respaldo,
  migración y rollback desde GitHub Actions.
- `verificacion-servidor`: comprobación de solo lectura de los requisitos técnicos del VPS.

### Modified Capabilities

Ninguna. Las capacidades existentes (`app-shell`, `screen-patterns`, `ui-design-system`) no
cambian de comportamiento.

## Impact

- Código: `src/**` (unas 40 de 45 clases tocan SQL específico de PostgreSQL), `config/`,
  `phinx.php`, `db/migrations/**`, `composer.json`, `composer.lock`, `compose.yaml`,
  `.env.example`, `tests/**`.
- Archivos nuevos: `deploy/` (Nginx, FPM, scripts), `.github/workflows/`, script de verificación
  en este cambio.
- Sistemas: base `flow_balance` y usuario nuevos en la MariaDB del VPS, un pool FPM y un vhost
  nuevos, un usuario de despliegue. El blog Drupal no debe verse afectado: RAM, conexiones
  (`max_connections = 25` compartido) y la caché FastCGI `drupal` son los puntos de contacto.
- Riesgo principal: portar dinero e idempotencia a otro motor. Se mitiga con la suite completa
  contra MariaDB antes de cualquier despliegue.
