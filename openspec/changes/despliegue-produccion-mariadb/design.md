## Context

Motivación y alcance: ver `proposal.md`. Lo que condiciona el diseño:

**Servidor** (VPS compartido con Drupal 11): 1 vCPU, 961 MB de RAM con swap ya en uso, PHP 8.3
FPM con `disable_functions` que bloquea `proc_open` y `putenv`, Nginx 1.24 sin HTTP/3 ni Brotli
confirmados, MariaDB 10.11 solo en `127.0.0.1` con `max_connections = 25` compartido, OPcache
con `validate_timestamps=1` cada 60 s y `realpath_cache_ttl` de 600 s.

**Código** (hallazgos que determinan el trabajo):

- `src/Shared/Database/DatabaseConfig.php:22-26` solo acepta `development` y `testing`; `:56`
  conecta con DSN `pgsql:` y `SET TIME ZONE 'UTC'`.
- 40 de 45 clases de `src/` contienen SQL de PostgreSQL: `::text` (81 usos), `RETURNING`
  (`CreateClient.php:16`, `CreateProject.php:20`, `RegisterUser.php:23`,
  `RecordPaymentCharge.php:24`, `RecordPayment.php:29,87`, `PostgresOwnedResourceRepository.php:116`),
  `ON CONFLICT` (`LoginUser.php:24`, `RecordPayment.php:29,87`, `RecordPaymentCharge.php:24`) y
  `to_timestamp` (`LoginUser.php:24,46,56`, `PostgresSessionPersistence.php:56-72`).
- Idempotencia: `RecordPayment.php:58` toma `pg_advisory_xact_lock` por clave de cuenta, después de
  bloquear el proyecto con `FOR UPDATE` (`PaymentExcess.php:14-15`). La clave es global a la
  cuenta, no por proyecto (comentario en `RecordPayment.php:57`).
- Conversión monetaria en una función SQL, `payment_amount_mxn`
  (`db/migrations/20261119000300_add_payment_exchange_rate.php:16-26`), usada desde
  `PaymentConversion.php:11,39-41` y `PostgresMonthlyReportReader.php:25-26`.
- Los lectores arman JSON en SQL con `json_agg`/`json_build_object`
  (`PostgresMonthlyReportReader.php:42-54`, `PostgresOwnedResourceRepository.php:64-67`), con cada
  importe convertido a texto para no perder precisión.
- Los ids son `bigint` identidad y la API los devuelve como texto (`id::text`). Los importes son
  `numeric` sin precisión fija, acotados con `CHECK (... < 100000000000000 AND scale(...) <= 2)`
  (`20261118000800_create_payment_charges.php:16`) y `scale(exchange_rate) <= 6`
  (`20261119000300_add_payment_exchange_rate.php:11-13`).
- `users.email_normalized` es una columna generada con `lower(btrim(...))`
  (`20261118000100_create_users.php:16`).
- La sesión se lee con `strtotime($row['expires_at'])` (`PostgresSessionPersistence.php:34-35`):
  hoy PostgreSQL devuelve el instante con su desfase; MariaDB devolverá texto sin zona.
- La política de caché privada solo existe en un plugin de Vite de desarrollo y vista previa
  (`frontend/astro.config.mjs:45-54`); `frontend/dist/` es estático (Astro sin adaptador).
- `config/routes.php:58` usa `https://flow-balance.test` como origen por omisión si falta
  `APP_ORIGIN`; `:70-73` exige `APP_SECRET` de 32 caracteres o más.
- Dotenv se carga con `createImmutable(...)->safeLoad()` (`config/database.php:8`), que no usa
  `putenv`, así que `disable_functions` no lo afecta.

## Goals / Non-Goals

**Goals:**

- Comportamiento observable idéntico entre PostgreSQL y MariaDB: mismos JSON, mismos redondeos,
  mismos códigos de error.
- Una sola ruta de despliegue repetible y reversible, sin tocar el blog.
- Cero secretos de la aplicación en GitHub ni en el repositorio.

**Non-Goals:**

- Portabilidad a MySQL 8 u otros motores: el destino es MariaDB 10.11. No se buscan abstracciones
  de dialecto.
- Migrar datos existentes, cambiar la interfaz o añadir observabilidad más allá de la
  comprobación de salud.
- Entorno de staging propio. La verificación previa usa un dominio provisional (ver Plan).

## Decisions

### D1. Reescribir las migraciones en lugar de mantener dos dialectos

No hay base de producción ni datos que conservar, así que se reescriben las 12 migraciones
existentes para MariaDB y se recrean las bases locales. Un solo historial limpio.
*Alternativa descartada:* migración de conversión incremental sobre el esquema de PostgreSQL;
añade complejidad para un camino que nunca se recorrerá.

### D2. Tipos

| PostgreSQL | MariaDB | Nota |
|---|---|---|
| `bigint GENERATED ... IDENTITY` | `BIGINT AUTO_INCREMENT` | Se castea a texto al leer para mantener ids como texto en la API |
| `numeric` + `scale()<=2`, `<1e14` | `DECIMAL(16,2)` | El `CHECK` de escala sobra; se conserva el de rango |
| `numeric` tipo de cambio, escala 6 | `DECIMAL(20,6)` | |
| `timestamptz` | `DATETIME(6)` en UTC | `TIMESTAMP` descartado: límite 2038 |
| `jsonb` (payload de sesión) | `JSON` (alias de `LONGTEXT` con `JSON_VALID`) | Solo se lee y escribe completo |
| `text` | `VARCHAR(n)` o `TEXT` según uso | |

### D3. Colación: insensible para texto de usuario, binaria para claves

El servidor usa `utf8mb4_unicode_ci`. Es adecuada para nombres y correo, pero
**las claves de idempotencia, `token_hash` y `key_hash` MUST ser `utf8mb4_bin`**: con una colación
insensible, `ABC` y `abc` colisionarían y romperían la idempotencia y el hash de sesión.

### D4. `email_normalized` calculado en PHP

El `lower(btrim(...))` con un conjunto de caracteres no se traduce a `TRIM` de MariaDB, que
recorta una sola cadena. Se guarda como columna normal con `UNIQUE`, y la aplicación la calcula
(ya busca por ese valor, `LoginUser.php:35`). *Alternativa descartada:* columna generada con
`REGEXP_REPLACE`, más frágil y difícil de probar.

### D5. Aislamiento `READ COMMITTED`

PostgreSQL trabaja por omisión en `READ COMMITTED`; InnoDB en `REPEATABLE READ`. Para conservar la
semántica de las lecturas dentro de `UnitOfWork`, la conexión fija
`SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED`, junto con
`time_zone='+00:00'` y un `sql_mode` estricto explícito (`STRICT_ALL_TABLES`,
`NO_ZERO_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`, `NO_ENGINE_SUBSTITUTION`).

### D6. Exclusión mutua de cobros: bloqueo de fila de la cuenta

Se reemplaza `pg_advisory_xact_lock` por `SELECT id FROM users WHERE id = ? FOR UPDATE`, tomado
**antes** de bloquear el proyecto. Es transaccional (se libera al terminar), cubre la clave
global de cuenta y serializa solo los cobros de la misma cuenta. El orden "cuenta, luego proyecto"
se aplica en todos los caminos que toman ambos, para evitar interbloqueos; el inicio de sesión
bloquea `login_throttles` y `users`, nunca proyectos.
*Alternativas descartadas:* `GET_LOCK` (de sesión, no transaccional; se filtra si la conexión
persiste o falla antes de `RELEASE_LOCK`) y confiar solo en el `UNIQUE (user_id, idempotency_key)`
(evita duplicados, pero no el cálculo de excedente sobre datos concurrentes).

### D7. Altas idempotentes sin `RETURNING`

`INSERT ... ON CONFLICT DO NOTHING RETURNING` pasa a
`INSERT ... ON DUPLICATE KEY UPDATE id = id` y se distingue inserción de repetición con
`rowCount()` (1 contra 0) y `lastInsertId()`; luego se lee el registro. Se descarta `INSERT IGNORE`
porque, además de duplicados, silencia otros errores de datos. `UPDATE ... RETURNING`
(`PostgresOwnedResourceRepository.php:116`) pasa a `UPDATE` y `SELECT` dentro de la misma
transacción, con la fila ya bloqueada. Los `INSERT ... RETURNING id` sencillos usan
`lastInsertId()`.

### D8. Sin función almacenada: la conversión se queda en una expresión SQL

`payment_amount_mxn` se elimina. `PaymentConversion::PAYMENT_AMOUNT` pasa a ser un fragmento
`CASE currency WHEN 'MXN' THEN ROUND(x, 2) WHEN 'USD' THEN ROUND(x * rate, 2) END`, generado por
un único método para que ambos lectores lo compartan. Así el usuario de la aplicación no necesita
`CREATE ROUTINE` ni se depende de `log_bin_trust_function_creators`. `ROUND` sobre `DECIMAL`
redondea la mitad alejándose de cero, igual que `round(numeric)` de PostgreSQL; una prueba de
valores frontera lo fija.

### D9. JSON armado en PHP, no en SQL

Los lectores dejan de usar `json_agg`/`json_build_object`. Consultan filas planas y PHP arma la
estructura. En MariaDB, `JSON_OBJECT` emite los `DECIMAL` como números JSON y se pierde el
formato de texto exacto que la API garantiza; armar en PHP evita esa clase de error. Cuesta un
poco de código y no un rendimiento apreciable: los reportes son mensuales y pequeños.

### D10. Fechas: leer como instante explícito

Las lecturas que hoy hacen `strtotime(columna)` pasan a seleccionar `UNIX_TIMESTAMP(columna)`
(la sesión está en UTC, D5) y las escrituras `to_timestamp(?)` pasan a `FROM_UNIXTIME(?)`. Así el
resultado no depende de `date.timezone` de PHP, que el VPS no garantiza.

### D11. Pruebas de caracterización antes de portar

Antes de tocar el SQL se capturan, sobre la rama actual con PostgreSQL, respuestas JSON de
referencia de los flujos clave (alta y edición de cliente y proyecto, cobros en MXN y USD con
excedente, reportes mensuales, idempotencia, sesión). La versión portada MUST reproducirlas
byte a byte. Es la red de seguridad principal para una migración que toca dinero.

### D12. Renombrar las clases `Postgres*`

`PostgresSessionPersistence`, `PostgresOwnedResourceRepository`, `PostgresMonthlyReportReader` y
`PostgresPaymentListReader` pasan a `Sql*` (con `git mv`) y se actualiza `config/container.php`.
Nombres engañosos se quedarían en el código por años.

### D13. Estructura en el servidor y activación de releases

```
/var/www/flowbalance/
  releases/<fecha>-<sha>/     public/ src/ config/ db/migrations/ vendor/ frontend/dist/
  current -> releases/...     enlace activo
  shared/.env                 modo 600
  shared/backups/             respaldos, 7 más recientes
```

Nginx apunta a `current` y pasa `$realpath_root` como `SCRIPT_FILENAME`. Con la ruta real de
cada release, OPcache y la caché de rutas ven archivos distintos al cambiar el enlace, **sin
recargar PHP-FPM** (que compartiría proceso maestro con el blog) y sin esperar la revalidación.
El `.env` compartido se enlaza dentro del release, donde Dotenv lo busca.

### D14. Un usuario de sistema, un usuario de base

El usuario `flowbalance` ejecuta el pool y recibe los despliegues; es distinto de `www-data` del
blog, por lo que no puede leer los archivos de Drupal. En MariaDB un único usuario
`flow_balance_app@localhost` con todos los privilegios solo sobre `flow_balance.*`.
*Alternativa descartada:* separar usuario de migraciones y de ejecución; es mínimo privilegio
real, pero duplica secretos y configuración en un servidor de una sola persona. Se reevaluará si
se agregan operadores.

### D15. Secretos

La aplicación lee su configuración de `shared/.env` en el servidor (`APP_SECRET`, `DB_*`,
`APP_ORIGIN`). GitHub guarda solo `DEPLOY_SSH_KEY` (secreto) y, como variables, `DEPLOY_HOST`,
`DEPLOY_USER`, `DEPLOY_KNOWN_HOSTS` (la huella no es secreta, pero se fija) y `APP_ORIGIN` (para
compilar `site` en Astro). Entorno `production` con revisores requeridos y restringido a `main`.
Se corrige `config/routes.php:58` y similares para que en producción un `APP_ORIGIN` ausente sea
un error y no el origen de desarrollo.
*Alternativa descartada:* generar el `.env` desde secretos de GitHub en cada despliegue; amplía
el alcance de GitHub a la base de datos y a la sesión sin necesidad.

### D16. Punto de salud

Se agrega `GET /api/health` (sin sesión, `Cache-Control: no-store`, ejecuta `SELECT 1`) para la
comprobación posterior a la activación. Es la única ruta de API nueva y tiene su tarea
explícita. *Alternativa descartada:* usar `/api/auth/csrf`, que abre sesión y escribe en la base.

### D17. Nginx replica la política privada

El vhost fija `Cache-Control: private, no-store` en `/dashboard`, `/clients`, `/projects`,
`/payments` y `/api/`, `no-cache` en `/service-worker.js`, e `immutable` en `/_astro/`
(`astro.config.mjs:45-54` y el service worker excluyen `/api` de su caché). El vhost no hereda
`fastcgi_cache` de la zona `drupal`. Solo gzip: Brotli y HTTP/3 no están confirmados en el
paquete de Ubuntu y `nginx -t` es una puerta en el despliegue.

### D18. Límites del pool de la aplicación

`pm = ondemand`, `max_children = 2`, `process_idle_timeout = 10s`, `memory_limit = 64M`,
`request_terminate_timeout = 30s`, `client_max_body_size 1m`. Con 961 MB y swap en uso, cada
proceso de Flow Balance no debe competir con los 5 hijos de 128 MB del blog. Máximo de 2
conexiones a MariaDB de las 25 disponibles.

### D19. Pipeline

Dos flujos: `ci.yml` (en cada push y pull request: PHPUnit con PHP 8.3 y servicio `mariadb:10.11`,
verificación de plataforma del lock, `astro build`) y `deploy.yml` (manual o tras `main`, entorno
`production`). El despliegue construye `vendor/` con `--no-dev --optimize-autoloader` y
`frontend/dist/`, arma el paquete desde una lista blanca (no una lista negra: lo nuevo no se
filtra por descuido), falla si aparece una ruta prohibida, sube con `rsync` sobre SSH con huella
fijada, respalda con `mariadb-dump --single-transaction`, migra, activa, consulta la salud y
revierte si falla.

## Risks / Trade-offs

- [Un cálculo monetario cambia de resultado] → D11 (referencia byte a byte), pruebas de frontera
  de redondeo, suite completa contra MariaDB en CI.
- [Las migraciones fallan a medias; el DDL de MariaDB no es transaccional] → respaldo previo a
  cada `migrate`, el release no se activa si falla y la migración MUST ser idempotente al
  reintentar; se prueba sobre una copia antes del primer despliegue.
- [Interbloqueo por el nuevo orden de bloqueos] → D6 documenta el orden; prueba concurrente de
  cobros simultáneos y login simultáneo de la misma cuenta.
- [Memoria agotada en un servidor de 961 MB con swap] → D18, y medir antes y después con la
  verificación del servidor; el pool es de bajo consumo y bajo demanda.
- [`max_connections = 25` compartido con Drupal] → máximo 2 conexiones de Flow; la verificación
  reporta el uso actual.
- [La llave de despliegue se filtra] → llave exclusiva, usuario sin root y sin acceso a Drupal,
  entorno protegido, huella fijada. El impacto máximo es código y datos de Flow Balance.
- [Cambio de colación altera comparaciones] → D3 y D4; pruebas de correo duplicado en mayúsculas y
  de claves que solo difieren en mayúsculas.
- [Un cambio de PHP 8.4 a 8.3 introduce una regresión sutil] → CI en 8.3; el lock se resuelve
  contra 8.3 y baja Symfony a la serie 7 (Phinx 0.16 la soporta).
- [Restaurar un respaldo es desconocido en la práctica] → una tarea ensaya la restauración en una
  base temporal antes de producción.

## Migration Plan

1. Rama `feat/despliegue-mariadb-vps`. Capturar referencias de caracterización sobre PostgreSQL
   (D11) y cerrar el trabajo de esquema y SQL con la suite completa verde en MariaDB 10.11 local.
2. PHP 8.3, lock regenerado y `APP_ENV=production` con fallos seguros.
3. Archivos de `deploy/` (Nginx, FPM, `.env.example` de producción) y el script de verificación.
4. Verificar el servidor con `verificar-servidor.sh`; aplicar sus pendientes (base, usuario,
   pool, usuario de sistema, DNS y certificado) de forma manual y documentada.
5. Primer despliegue contra un dominio provisional con la base ya creada; recorrer el flujo
   completo (registro, cliente, proyecto, cobro MXN y USD, reporte, cierre de sesión).
6. Fijar el subdominio definitivo en `APP_ORIGIN`, emitir su certificado y desplegar.

Reversión: cambiar el enlace `current` al release anterior (inmediato, sin reconstruir). Si
hubo migraciones, restaurar el respaldo previo a esa migración. El blog nunca se detiene en
ninguno de los casos.

## Open Questions

- Subdominio definitivo de Flow Balance. Solo bloquea DNS, certificado y el valor de
  `APP_ORIGIN` del primer despliegue real, no el trabajo previo.
- Si Playwright se ejecuta también en CI o solo localmente. No altera el diseño; se decide al
  escribir `ci.yml`.
