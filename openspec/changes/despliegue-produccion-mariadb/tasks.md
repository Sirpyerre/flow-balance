## 1. Red de seguridad antes de portar (sobre PostgreSQL, rama actual)

- [ ] 1.1 Ejecutar la suite actual (`composer test`, `tests/`) y anotar el resultado base. Verificar: todo verde, o la lista de fallos preexistentes queda registrada en este archivo.
- [ ] 1.2 Crear pruebas de caracterización en `tests/Characterization/` con respuestas JSON de referencia en `tests/Characterization/golden/` para: alta y edición de cliente y proyecto, cobro MXN, cobro USD con tipo de cambio y con excedente, cargo adicional, corrección de cobro, reporte mensual, lista de cobros, registro, inicio y cierre de sesión. Verificar: las pruebas pasan sobre PostgreSQL y generan los archivos de referencia.
- [ ] 1.3 Agregar pruebas de frontera de redondeo en `tests/Unit/` (0.005, 0.015, 100.005 y 17.123456 USD, importes cercanos a 1e14) y de concurrencia en `tests/Integration/` (dos cobros simultáneos con la misma clave; cobro y login simultáneos de la misma cuenta). Verificar: pasan sobre PostgreSQL.

## 2. Plataforma local: MariaDB 10.11 y PHP 8.3

- [ ] 2.1 Cambiar `compose.yaml` y `db/bootstrap/` a `mariadb:10.11` con `utf8mb4`, crear las bases `flow_balance` y `flow_balance_test` con sus usuarios dedicados, y actualizar `.env.example` (puertos, `DB_*`, `TEST_DB_*`; quitar `POSTGRES_PASSWORD`). Verificar: `docker compose up -d` deja la base sana y se puede conectar con ambos usuarios.
- [ ] 2.2 En `composer.json` declarar `php` en `~8.3.0 || ~8.4.0`, `config.platform.php` en `8.3.0`, reemplazar `ext-pdo_pgsql` por `ext-pdo_mysql`, y regenerar `composer.lock`. Verificar: `composer check-platform-reqs` y `composer install --no-dev` sin errores, y el lock ya no contiene paquetes que pidan PHP ≥ 8.4.
- [ ] 2.3 Fijar la versión de PHP del proyecto local para ejecutar contra 8.3 con Herd. Verificar: `php -v` en el proyecto muestra 8.3 y la suite de caracterización sigue ejecutándose.

## 3. Conexión y configuración

- [ ] 3.1 Reescribir `src/Shared/Database/DatabaseConfig.php`: DSN `mysql:` con `charset=utf8mb4`, `ATTR_EMULATE_PREPARES => false`, `time_zone='+00:00'`, `READ COMMITTED` y `sql_mode` estricto; aceptar `production` con `DB_*`; mantener la protección de la base de pruebas. Verificar: `tests/Unit/DatabaseConfigTest.php` cubre `production`, entorno desconocido y base de pruebas protegida.
- [ ] 3.2 Hacer que en `production` falten o sean inválidos `APP_ORIGIN` y `APP_SECRET` produzca un error genérico y no el valor de desarrollo (`config/routes.php:58,69-78,127,145,151,165`); centralizar la lectura de configuración. Verificar: prueba con `APP_ORIGIN` ausente y con `APP_SECRET` de 20 caracteres responde 503 sin emitir cookie.
- [ ] 3.3 Adaptar `phinx.php` al adaptador `mysql` y a `production`. Verificar: `composer db:status` conecta a MariaDB y rechaza un entorno que no coincide con `APP_ENV`.

## 4. Esquema

- [ ] 4.1 Reescribir `db/migrations/20261118000100_create_users.php` y `20261118000200_create_authentication_state.php` para MariaDB: `AUTO_INCREMENT`, `DATETIME(6)`, `JSON`, `utf8mb4_bin` en `token_hash` y `key_hash`, `email_normalized` como columna normal con `UNIQUE`, sin `LOCK TABLE` en `down()`. Verificar: migrar y revertir sobre base vacía sin errores.
- [ ] 4.2 Reescribir `20261118000300` a `20261118000800` (clientes, proyectos, cobros, ampliaciones de ingresos y cargos): `DECIMAL(16,2)`, `CHECK` de rango sin `scale()`, `utf8mb4_bin` en claves de idempotencia, índices equivalentes. Verificar: `tests/Integration/IncomeMigrationTest.php` adaptado pasa, y un `INSERT` con más de dos decimales o fuera de rango falla.
- [ ] 4.3 Reescribir `20261119000100` a `20261119000400` (correcciones, entrega, tipo de cambio, revisiones del acuerdo): `DECIMAL(20,6)` para el tipo de cambio, sin `CREATE FUNCTION payment_amount_mxn`, `JSON_VALUE` en lugar de `->>`. Verificar: migrar desde cero y revertir completo sin errores.
- [ ] 4.4 Ejecutar el ciclo completo `migrate`, `rollback`, `migrate` dos veces seguidas. Verificar: el estado final es idéntico y `composer db:status` no reporta pendientes.

## 5. Capa de datos

- [ ] 5.1 Renombrar con `git mv` las clases `Postgres*` a `Sql*` en `src/Identity/Infrastructure/` y `src/Finance/Infrastructure/`, y actualizar `config/container.php`. Verificar: `grep -ri postgres src config` no encuentra nada y el contenedor resuelve todos los servicios.
- [ ] 5.2 Portar identidad: `RegisterUser.php`, `LoginUser.php` (limitador con `FOR UPDATE`, `FROM_UNIXTIME`, alta idempotente) y `SqlSessionPersistence.php` (`UNIX_TIMESTAMP` en lecturas, `FROM_UNIXTIME` en escrituras). Verificar: pruebas de registro con correo duplicado en mayúsculas, bloqueo por intentos, expiración por inactividad y por tiempo absoluto, y que las caracterizaciones de sesión coinciden.
- [ ] 5.3 Portar altas simples: `CreateClient.php`, `CreateProject.php`, `SqlOwnedResourceRepository.php` (`UPDATE` más `SELECT` en la misma transacción), con `lastInsertId()` y ids como texto. Verificar: las caracterizaciones de cliente y proyecto coinciden byte a byte.
- [ ] 5.4 Reemplazar la conversión monetaria: un único fragmento SQL en `PaymentConversion.php`, sin función almacenada, usado también por el lector mensual. Verificar: las pruebas de frontera de la tarea 1.3 pasan con los mismos valores.
- [ ] 5.5 Portar cobros: `RecordPayment.php` (bloqueo `FOR UPDATE` sobre la cuenta antes del proyecto, alta con `ON DUPLICATE KEY UPDATE id = id` y `rowCount()`), `RecordPaymentCharge.php`, `PaymentExcess.php` (sin `::text`, `GREATEST` y `CAST` en `DECIMAL`). Verificar: caracterización de cobros MXN, USD y excedente, y las pruebas de concurrencia de la tarea 1.3 sin duplicados ni interbloqueos.
- [ ] 5.6 Portar lectores: `SqlMonthlyReportReader.php` y `SqlPaymentListReader.php`, armando la estructura en PHP sin `json_agg`, con importes como texto. Verificar: caracterización de reporte mensual y lista de cobros coinciden, y los totales tienen el formato decimal original.
- [ ] 5.7 Buscar restos de dialecto: `grep -rnE "::text|ON CONFLICT|RETURNING|to_timestamp|json_agg|pg_advisory|ILIKE|btrim" src config db`. Verificar: sin resultados.
- [ ] 5.8 Ejecutar la suite completa de PHPUnit contra MariaDB 10.11. Verificar: todo verde, con la caracterización y las pruebas de concurrencia incluidas.

## 6. Salud y encabezados desde la aplicación

- [ ] 6.1 Agregar `GET /api/health` en `config/routes.php` y un manejador que ejecute `SELECT 1`, sin sesión y con `Cache-Control: no-store`; es la única ruta de API nueva. Verificar: 200 con la base arriba, 503 genérico con la base caída, sin abrir sesión ni escribir filas.
- [ ] 6.2 Prueba de que las rutas existentes de `/api` siguen respondiendo igual que antes de este cambio. Verificar: las caracterizaciones de la tarea 1.2 pasan sin cambios de contrato.

## 7. Configuración del servidor (archivos versionados)

- [ ] 7.1 Crear `deploy/nginx/flow-balance.conf`: HTTPS con redirección desde HTTP, `root` en `current`, `$realpath_root` en `SCRIPT_FILENAME`, solo `/api` hacia PHP, `404` para otros `.php` y archivos ocultos, `Cache-Control` privado en `/dashboard`, `/clients`, `/projects`, `/payments` y `/api/`, `no-cache` en `/service-worker.js`, `immutable` en `/_astro/`, `client_max_body_size 1m`, cabeceras de seguridad, solo gzip y sin `fastcgi_cache`. Verificar: `nginx -t` con la configuración del VPS en un contenedor de prueba o en el servidor, y `curl -I` a cada ruta muestra el encabezado esperado.
- [ ] 7.2 Crear `deploy/php-fpm/flowbalance.conf` con el pool `flowbalance`: usuario y socket propios, `ondemand`, `max_children = 2`, `process_idle_timeout = 10s`, `memory_limit = 64M`, `request_terminate_timeout = 30s`, `open_basedir`. Verificar: `php-fpm8.3 -t` y una solicitud real bajo 2 conexiones simultáneas.
- [ ] 7.3 Crear `deploy/env.production.example` sin valores reales, y `deploy/README.md` (en español) con el aprovisionamiento manual: usuario de sistema, directorios, `shared/.env` modo 600, base y usuario de MariaDB limitados a `flow_balance.*`, DNS y certbot. Verificar: un revisor sigue el documento en una máquina limpia y llega a un pool y un vhost funcionales; ninguna contraseña aparece en el archivo.
- [ ] 7.4 Ensayar el respaldo y la restauración con `mariadb-dump --single-transaction` sobre una copia en base temporal. Verificar: los conteos de tablas y la suma de importes coinciden tras restaurar.

## 8. Script de verificación del servidor

- [ ] 8.1 Revisar `verificar-servidor.sh` (en este cambio) contra el servidor real: ejecutarlo con `! ssh <usuario>@<host> 'bash -s' < openspec/changes/despliegue-produccion-mariadb/verificar-servidor.sh`. Verificar: termina sin modificar nada, la salida no contiene secretos y marca como pendientes lo que aún no existe.
- [ ] 8.2 Resolver las fallas y advertencias del reporte (incluido el estado de `php8.3-fpm`, `date.timezone`, `disable_functions` de CLI y `fastcgi_cache` global). Verificar: segunda ejecución con cero fallas.

## 9. Pipeline

- [ ] 9.1 Crear `.github/workflows/ci.yml`: PHP 8.3, servicio `mariadb:10.11`, `composer install`, `composer check-platform-reqs`, PHPUnit y `npm ci && npm run build` en `frontend/`. Verificar: corre en un pull request y falla si se rompe una prueba.
- [ ] 9.2 Crear `deploy/package.sh` que arma el paquete desde una lista blanca y falla si aparece `docs/`, `specs/`, `openspec/`, `tests/`, `.specify/`, `.agents/`, `.claude/`, `compose.yaml`, `db/seeders/`, `db/bootstrap/`, `frontend/src/`, `node_modules/` o algún `.env`. Verificar: ejecutarlo localmente y listar el paquete; agregar a propósito un archivo prohibido hace fallar el script.
- [ ] 9.3 Crear `deploy/release.sh` (se ejecuta en el servidor, sin root): crea el release, enlaza `shared/.env`, respalda la base de Flow, migra, cambia `current` de forma atómica, conserva 3 releases y solo hace `nginx -t` sin recargar. Verificar: ensayo en el servidor con un release de prueba; una migración que falla deja el enlace anterior activo.
- [ ] 9.4 Crear `.github/workflows/deploy.yml`: entorno `production` con revisores y restringido a `main`, `DEPLOY_SSH_KEY` como secreto, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KNOWN_HOSTS` y `APP_ORIGIN` como variables, huella fijada sin `StrictHostKeyChecking=no`, `rsync` del paquete, ejecución de `release.sh`, consulta a `/api/health` y reversión si falla. Verificar: un despliegue de prueba exitoso, uno con huella alterada que aborta antes de transferir, y uno con salud fallida que revierte; revisar que los registros no muestran ningún secreto.
- [ ] 9.5 Crear la llave SSH de despliegue exclusiva, instalar su parte pública en `authorized_keys` del usuario `flowbalance` y cargar la privada como secreto. Verificar: el usuario no tiene root ni acceso de lectura a los archivos del blog (`sudo -l`, intento de lectura de `/var/www` de Drupal).

## 10. Primer despliegue y cierre

- [ ] 10.1 Desplegar a un dominio provisional y recorrer el flujo completo: registro, cliente, proyecto, cobro en MXN y USD, excedente, reporte mensual y cierre de sesión. Verificar: sin errores en `error.log` de Nginx y FPM, y el blog sigue respondiendo.
- [ ] 10.2 Comprobar la interfaz del sitio compilado a 360 px y 1080 px en tema oscuro y claro, y la instalación de la PWA. Verificar: capturas sin diferencias respecto de desarrollo y `Cache-Control` correcto en las vistas privadas.
- [ ] 10.3 Medir RAM, swap, conexiones de MariaDB y tiempos de respuesta del blog antes y después. Verificar: el blog no empeora de forma apreciable y el uso de swap no crece de forma sostenida.
- [ ] 10.4 Fijar el subdominio definitivo en `APP_ORIGIN`, emitir el certificado, desplegar y repetir el flujo de la tarea 10.1. Verificar: la cookie `__Host-flow_balance` se establece con `Secure`, `HttpOnly` y `SameSite=Lax`, sin `Domain`.
- [ ] 10.5 Actualizar `openspec/config.yaml` (stack: PHP 8.3, MariaDB 10.11, despliegue) y `AGENTS.md` si cambian los comandos. Verificar: `openspec validate despliegue-produccion-mariadb --strict` pasa.
