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
- El correo se normaliza en SQL con `lower(btrim(x, <espacio, tab, LF, CR, FF, VT>))`: lo hace la
  columna generada `users.email_normalized` (`20261118000100_create_users.php:16`) y también
  `LoginUser.php:17`, que le pregunta a la base el valor antes de buscar.
- El limitador de inicios de sesión guarda sus fallos en `login_throttles.failed_at`, un arreglo
  `timestamptz[]` (`20261118000200_create_authentication_state.php:26`), que `LoginUser.php:26,45,55`
  lee con `array_to_json` y escribe con `ARRAY(SELECT ... json_array_elements_text(...))`;
  `blocked_until` se lee con `extract(epoch ...)`.
- El dashboard formatea importes con `trim_scale(...)::text` (`4800`, no `4800.00`) y las fechas de
  creación con `created_at AT TIME ZONE 'America/Mexico_City'`
  (`PostgresAccountDataReader.php:16-19`); la referencia de caracterización ya fija ambos formatos.
- Un correo duplicado se reconoce por el SQLSTATE `23505` y el nombre de la restricción entre comillas
  dobles en el mensaje (`RegisterUser.php:29`), formato propio de PostgreSQL.
- Cinco consultas usan `FILTER (WHERE ...)` (`PostgresOwnedResourceRepository.php:63`,
  `PostgresMonthlyReportReader.php:46`, `PostgresPaymentCorrectionRepository.php:43-44`,
  `PostgresProjectBalanceReportReader.php:22`) y varias `CAST(? AS numeric)`
  (`PaymentConversion.php:36-37`, `PaymentExcess.php:27-28`).
- Las pruebas de integración existentes también usan SQL de PostgreSQL: `->>`, `RETURNING`,
  `repeat()` y `SET lock_timeout` (`AccountAccessTest.php`, `IncomeIdempotencyTest.php`).
- La sesión se lee con `strtotime($row['expires_at'])` (`PostgresSessionPersistence.php:34-35`):
  hoy PostgreSQL devuelve el instante con su desfase; MariaDB devolverá texto sin zona.
- La política de caché privada solo existe en un plugin de Vite de desarrollo y vista previa
  (`frontend/astro.config.mjs:45-54`); `frontend/dist/` es estático (Astro sin adaptador).
- `config/routes.php:58` usa `https://flow-balance.test` como origen por omisión si falta
  `APP_ORIGIN`; `:70-73` exige `APP_SECRET` de 32 caracteres o más.
- Dotenv se carga con `createImmutable(...)->safeLoad()` (`config/database.php:8`), que no usa
  `putenv`, así que `disable_functions` no lo afecta.
- El blog ya está detrás de Cloudflare. `/etc/nginx/sites-enabled/monoforms.conf:26-48` declara
  `set_real_ip_from` para los rangos de Cloudflare y `real_ip_header CF-Connecting-IP` antes de sus
  bloques `server` (desde la línea 61), y los tres usan `/etc/nginx/ssl/cloudflare-origin.pem`, un
  certificado de origen con SAN `*.monoforms.com` y `monoforms.com`, vigente hasta 2041.
- Resultado de `verificar-servidor.sh` del 2026-11-20: 0 fallas; `ufw` permite solo 22, 80 y 443;
  `slow_query_log=1` y `general_log=0`; 472 MB de RAM disponibles.

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
| `numeric` + `scale()<=2`, `<1e14` (`agreed_amount`, `platform_fee`, `withheld_tax_amount`, `payment_charges.amount`) | `DECIMAL(22,8)` + `CHECK (x = ROUND(x, 2))` y el de rango | MariaDB redondea en silencio los decimales sobrantes de una columna `DECIMAL`, también en modo estricto (medido: `1.001` en `DECIMAL(16,2)` se guarda como `1.00`, solo con un aviso); una columna más ancha con ese `CHECK` los rechaza |
| `numeric(20,6)` (`payments.client_paid_amount`) | `DECIMAL(20,6)` | Igual que en PostgreSQL, que también redondeaba |
| `numeric` tipo de cambio, `scale()<=6` | `DECIMAL(26,12)` + `CHECK (x = ROUND(x, 6))` y el de rango | Rechaza de 7 a 12 decimales; solo un valor con ceros del decimal 7 al 12 y algo después del 13 se redondearía sin error, y la validación de PHP lo cubre |
| `numeric` de `project_agreement_revisions` | `DECIMAL(22,8)` | Sin comprobación de escala, como en PostgreSQL |
| `timestamptz` | `DATETIME(6)` en UTC | `TIMESTAMP` descartado: límite 2038 |
| `jsonb` (payload de sesión) | `JSON` (alias de `LONGTEXT` con `JSON_VALID`) | Solo se lee y escribe completo |
| `timestamptz[]` (`login_throttles.failed_at`) | `JSON NOT NULL DEFAULT '[]'` con segundos epoch enteros | Ver D21 |
| `text` | `VARCHAR(n)` o `TEXT` según uso | |

### D3. Colación: insensible para texto de usuario, exacta para claves y valores discretos

El servidor usa `utf8mb4_unicode_ci`: sirve para nombres, pero medido sobre un `UNIQUE` hace colisionar
mayúsculas (`ABC`/`abc`), acentos (`nandu`/`ñandú`) y espacios finales (`k`/`k␠`); PostgreSQL no hacía
ninguna de las tres cosas. Ni siquiera `utf8mb4_bin` basta: es `PAD SPACE` y sigue igualando `k` con
`k␠`. Las columnas que identifican o comparan valores exactos MUST ser `utf8mb4_nopad_bin`:
`email_normalized`, `token_hash`, `key_hash`, `idempotency_key`, `request_key`, `request_fingerprint`,
`fingerprint`, `currency`, `agreed_currency`, `charge_type`, `source_type`, `action` y `delivery_status`
(las cinco últimas, para que `CHECK (x IN (...))` distinga mayúsculas, como en PostgreSQL).
Por la misma razón `PAD SPACE`, `name = btrim(name)` no se traduce a `name = TRIM(name)` (siempre
verdadero con espacios finales); se escribe `CHAR_LENGTH(name) = CHAR_LENGTH(TRIM(name))`.

### D4. La normalización del correo vive en PHP

`TRIM` de MariaDB recorta una sola cadena, no un conjunto de caracteres, así que
`lower(btrim(x, <conjunto>))` no se traduce, y una columna generada con `REGEXP_REPLACE` sería más
frágil y difícil de probar. La normalización se mueve a un único método de PHP, usado por registro y
por inicio de sesión: recorta ese mismo conjunto (espacio, tab, LF, CR, FF, VT) y pasa a minúsculas
con `mb_strtolower`. `RegisterUser` inserta el valor ya normalizado en una columna normal con
`UNIQUE` y colación `utf8mb4_nopad_bin` (D3), y `LoginUser` deja de consultarlo a la base. Una prueba de paridad fija el resultado con
mayúsculas, espacios, tabuladores y letras acentuadas. *Alternativa descartada:* columna generada;
obliga a mantener dos implementaciones del mismo criterio.

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

El usuario `flowdeploy` ejecuta el pool, es dueño del código y recibe los despliegues; es distinto
de `www-data` del blog, por lo que no puede leer los archivos de Drupal. Tiene shell (el pipeline
y el respaldo local entran por SSH y ejecutan comandos, algo que `nologin` impide), sin sudo y sin
contraseña. El pool se sigue llamando `flowbalance` (el socket no cambia); solo cambia el usuario
que lo ejecuta. En MariaDB un único usuario `flow_balance_app` (para `localhost` y `127.0.0.1`)
con todos los privilegios solo sobre `flow_balance.*`.
*Riesgo aceptado:* si PHP se compromete, el atacante puede reescribir el código del release y leer
`shared/.env`. Lo acotan `open_basedir`, `disable_functions`, el pool de 2 procesos y que el
usuario no es el del blog; el impacto máximo es código y datos de Flow Balance.
*Alternativas descartadas:* (a) usuario de despliegue separado del que ejecuta PHP: impide que un
PHP comprometido modifique el código, pero añade permisos de grupo en `releases/` y `.env` para un
servidor de una sola persona; (b) separar usuario de migraciones y de ejecución en MariaDB:
mínimo privilegio real, pero duplica secretos. Ambas se reevaluarán si se agregan operadores.

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
`fastcgi_cache` de la zona `drupal`. Solo gzip: el paquete de Ubuntu no compila Brotli ni HTTP/3
(confirmado en el servidor), y no hacen falta porque Cloudflare los sirve al visitante. `nginx -t`
es una puerta en el despliegue.

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
fijada, respalda con `mariadb-dump --single-transaction`, migra, activa, consulta la salud desde el propio servidor (D20) y
revierte si falla.

### D20. Cloudflare delante del origen

- **IP real del cliente: se reutiliza la configuración existente, sin duplicarla.** El vhost de
  Flow hereda `set_real_ip_from` y `real_ip_header CF-Connecting-IP`, de modo que `REMOTE_ADDR`
  en PHP es la IP del visitante y el limitador de inicios de sesión (`ClientIpResolver`) cuenta
  por ella. `TRUSTED_API_PROXY_PEERS` y `CLIENT_IP_PROXY_TOKEN` quedan vacíos. Un encabezado
  falsificado desde fuera de los rangos de Cloudflare se ignora. *Alternativa descartada:* mover
  esa configuración a un fragmento compartido; implica editar `monoforms.conf` y arriesgar el
  blog por un cambio de orden estético.
- **Certificado: se reutiliza el comodín de origen** (`*.monoforms.com`, hasta 2041). No se
  instala certbot ni se guarda ningún token de la API de Cloudflare en el servidor. *Alternativa
  descartada:* Let's Encrypt por DNS-01; exige un token con permiso de editar el DNS de toda la
  zona en un servidor compartido con el blog.
- **Modo SSL de Cloudflare: Full (strict).** Con el origen ya en HTTPS y un certificado de origen
  válido, es el modo que no degrada el cifrado de extremo a extremo.
- **Caché de Cloudflare.** Omitir (bypass) todo el subdominio salvo `/_astro/*`, y apagar Rocket
  Loader, Auto Minify y Email Obfuscation: reescriben HTML y JavaScript y pueden romper las islas
  de React y el service worker. La política `private, no-store` de D17 sigue siendo la fuente
  de verdad.
- **La salud se comprueba desde el servidor, no desde el runner.** `release.sh` consulta
  `/api/health` por loopback con `curl -k --resolve <host>:443:127.0.0.1`. El `-k` se justifica
  porque la CA de origen de Cloudflare no está en el almacén del sistema y la conexión es local.
  Una consulta desde GitHub pasaría por el WAF de Cloudflare, que podría desafiarla como bot y
  disparar una reversión falsa.

### D21. Limitador de inicios de sesión sin arreglos

MariaDB no tiene arreglos. `login_throttles.failed_at` pasa a `JSON NOT NULL DEFAULT '[]'` con
segundos epoch enteros. PHP ya filtra y arma la lista (`LoginUser.php:50-52`), así que el SQL solo
guarda y devuelve el texto: desaparecen `array_to_json`, el `ARRAY(...)` y los `strtotime` sobre
fechas, y con ellos la dependencia de la zona horaria. `blocked_until` es `DATETIME(6)` y se lee con
`UNIX_TIMESTAMP()`. El límite (5 fallos en 900 s) y las respuestas no cambian.
*Alternativa descartada:* una tabla hija con una fila por intento; añade un `JOIN` y una escritura
por fallo para guardar como máximo 5 valores.

### D22. Formatos que hoy produce PostgreSQL se reproducen en PHP

`trim_scale(client_paid_amount)::text` devuelve `4800`; un `DECIMAL(16,2)` devolvería `4800.00`. El
formato se reproduce al armar la fila, con una función única compartida por los lectores. Las fechas
de creación (`created_at AT TIME ZONE 'America/Mexico_City'`) necesitarían `CONVERT_TZ` con nombre de
zona, que solo funciona si el servidor tiene cargadas las tablas de zonas horarias; no se asume.
La conversión de UTC a `America/Mexico_City` se hace en PHP con `DateTimeImmutable`.
*Alternativa descartada:* cargar las tablas con `mysql_tzinfo_to_sql` en el VPS; es cambiar un
servidor compartido con el blog por algo que PHP resuelve solo.

### D23. Unicidad y errores del motor

Una clave duplicada llega en MariaDB como SQLSTATE `23000` con código de controlador `1062` y el
nombre de la restricción entre comillas simples (`Duplicate entry ... for key '...'`). La detección
de `RegisterUser.php:29` pasa a `errorInfo[1] === 1062` más el nombre de la restricción, en un único
método. `SAVEPOINT`, `ROLLBACK TO SAVEPOINT` y `RELEASE SAVEPOINT` de `UnitOfWork.php:31-38` son
válidos en InnoDB sin cambios. Un interbloqueo (código 1213) llega como `PDOException` y la ruta ya
lo convierte en el 503 genérico (`config/routes.php:44-48`).

### D24. Construcciones sin equivalente directo

| PostgreSQL | MariaDB |
|---|---|
| `agg(x) FILTER (WHERE c)` | `agg(CASE WHEN c THEN x END)` |
| `x::text`, `CAST(x AS text)` | `CAST(x AS CHAR)` |
| `to_char(d, 'YYYY-MM')` | `DATE_FORMAT(d, '%Y-%m')` |
| `CAST(? AS numeric)` | `CAST(? AS DECIMAL(22,8))` para importes y `DECIMAL(26,12)` para la tasa (tan anchos como su columna, D2); `DECIMAL(22,2)` solo para un valor que ya viene redondeado a centavos. Si el parámetro va directo a una columna, sin `CAST` |
| `INSERT ... SELECT ... ON CONFLICT DO NOTHING` | `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE tabla.columna = tabla.columna`, con la columna **calificada**: `id` sería ambiguo entre la tabla de origen y la de destino |
| `ORDER BY name` (PostgreSQL ordena por bytes con `C.UTF-8`) | `ORDER BY name COLLATE utf8mb4_bin`; la colación de la columna ignora mayúsculas y acentos |
| `COUNT(*) FILTER (WHERE c)` en una consulta con `LEFT JOIN` | `COUNT(CASE WHEN <fila existe> AND c THEN 1 END)`: la fila sin coincidencia no debe contarse |
| `json_agg(...)` y totales en una consulta | Filas planas con los totales en cada fila mediante `SUM(...) OVER ()`; el JSON se arma en PHP |
| Booleanos (`NOT EXISTS`, `x IS NULL`) | MariaDB los devuelve como `1`/`0`; se convierten con `(bool)` para que la API siga entregando `true`/`false` |
| `(a, b) < (?, ?)` | igual (comparación de filas soportada) |
| `GREATEST`, `COALESCE`, `ROUND` | igual |

La fila de `CAST ... numeric` es una trampa doble. En MariaDB `DECIMAL` sin parámetros es `DECIMAL(10,0)` y
truncaría los importes sin avisar. Y un `CAST` a una escala menor que la de la columna (por ejemplo
`DECIMAL(16,2)` para un importe) redondearía antes de que el `CHECK` de D2 pudiera rechazarlo. La precisión
se fija en cada `CAST`, tan ancha como la columna que alimenta; `importe × tasa` cabe de sobra (46 dígitos
de 65). Las fronteras de `PaymentRoundingTest` lo comprueban.

### D25. Las pruebas de migración tienen su propia base

`IncomeMigrationTest` crea hoy un esquema de PostgreSQL por ejecución (`PostgresAdapter(['schema' => ...])` y
`SET LOCAL search_path`, `IncomeMigrationTest.php:11-25`) y lo descarta con la transacción. En MariaDB un
esquema es una base de datos y el DDL confirma de forma implícita, así que ni ese aislamiento ni ese
`ROLLBACK` son posibles. Se provisiona una tercera base, `flow_balance_migration_test`, con su usuario
`flow_balance_migration_test_app` y solo para esa prueba: ejecuta la cadena de migraciones sobre una base
vacía y la deja vacía al terminar. `DatabaseConfig` gana el entorno `migration-testing`, con prefijo
`MIGRATION_TEST_DB_` y la misma protección que `testing` (nombre de base y de usuario fijos), para que nada
más pueda apuntar ahí por error.
*Alternativa descartada:* ejecutarla en `flow_balance_test` y limpiar las tablas al terminar; las
migraciones usan nombres fijos (`users`, `payments`...) y chocarían con las demás pruebas si corren juntas.

### D26. Política de caché del vhost, ensayada

El vhost se ensayó en un contenedor igual al VPS (Ubuntu 24.04, `nginx` 1.24 y `php8.3-fpm` de Ubuntu, el mismo
`disable_functions`, y OPcache con `validate_timestamps=1`, revalidación de 60 s y `realpath_cache_ttl=600`).
De ahí salieron cuatro reglas:

- Los activos con hash llevan `immutable` **solo en respuestas exitosas** (`add_header` sin `always`); con `always`,
  un 404 de un activo inexistente quedaba guardado un año.
- El HTML público (inicio, ingreso, registro) lleva `no-cache`: una página guardada que apunte a activos de un
  release ya eliminado se vería rota.
- `/api/` oculta el `Cache-Control` de PHP y pone el suyo (`private, no-store`) con `always`: una sola cabecera,
  también en los errores que genera Nginx (un 502 con PHP caído sigue siendo privado).
- `add_header` no se hereda en un bloque que define el suyo, así que las tres cabeceras de seguridad viven en un
  fragmento que cada `location` incluye.

También quedó comprobado por contraste que `$realpath_root` es necesario: con la ruta del enlace sin resolver, tras
cambiar de release siguió activo el código anterior; con él, el release nuevo y la reversión se sirven al instante
sin recargar PHP-FPM.

### D27. Un respaldo se verifica antes de confiar en él

En el ensayo de respaldo, un `mariadb-dump` que no llegó a ejecutarse produjo un archivo de 20 bytes y la restauración
terminó «sin errores». Un respaldo vacío no falla: es un falso verde. `release.sh` MUST usar `pipefail` y comprobar que
el volcado termina con `-- Dump completed` antes de migrar; si no, aborta sin tocar la base. El usuario de la base se
crea para `localhost` y para `127.0.0.1`, porque MariaDB los trata como anfitriones distintos si resuelve nombres de
forma estricta.

### D28. Aprovisionamiento con un playbook propio, acotado e idempotente

El Ansible de monoforms reconstruye el servidor completo (`apt upgrade dist` con reinicio, reescribe
`nginx.conf`, `sshd_config`, UFW y la clave de root de MariaDB, y reinicia Nginx, PHP-FPM y MariaDB
al final), así que ejecutarlo de nuevo afectaría al blog. Flow Balance lleva su propio playbook en
`deploy/ansible/` (no forma parte del paquete desplegado, que es de lista blanca) que se ejecuta
desde la Mac del operador, no desde CI. Cubre solo lo que es de Flow: preflight de solo lectura
(PHP 8.3-FPM y Nginx instalados, MariaDB activo y accesible como administrador, existen
`cloudflare-origin.pem` y `.key`, `flow_host` definido y distinto del marcador), usuario y directorios,
base y usuarios de MariaDB, `shared/.env`, pool, vhost y `/usr/local/bin/flowbalance-dump` (D29).
No toca `nginx.conf`, `php.ini`, el pool `www`, `sshd`, UFW, fail2ban ni la base `drupal`, y no hace
`apt upgrade` ni reinicios: valida con `nginx -t` y `php-fpm8.3 -t` y solo recarga; si la validación
falla retira el enlace del vhost o el archivo del pool y aborta, para que el blog siga sirviendo.
Una segunda ejecución MUST dar `changed=0` y funcionar con `--check`.
La fuente de verdad de vhost, pool y `.env` de ejemplo son los archivos de `deploy/` ya ensayados; el
playbook los lee y sustituye `@FLOW_HOST@`, sin copias.
Los secretos nunca pasan por la Mac ni por GitHub (no hace falta Ansible Vault): si `shared/.env` no
existe, el servidor genera `APP_SECRET` y `DB_PASSWORD` con `openssl` y escribe el archivo en modo
600; si existe, se lee `DB_PASSWORD` con `no_log` solo para comprobar que el usuario de MariaDB
exista, y nunca se rota ni se sobrescribe. La salida no imprime valores.
*Alternativas descartadas:* (a) un rol dentro del repo de monoforms: dos fuentes de verdad para el
vhost y mezcla de proyectos; (b) mantener solo el README manual: no sobrevive a reconstruir el droplet
con Terraform y Ansible, que devolverían el blog y no Flow.

### D29. Respaldo local por flujo, sin copia en el servidor

El respaldo periódico lo ejecuta el operador a mano desde su Mac con `deploy/backup-local.sh`: abre
SSH como `flowdeploy`, ejecuta `/usr/local/bin/flowbalance-dump` y recibe el volcado comprimido por
la misma conexión directamente en disco local. El servidor no escribe ninguna copia, así que no hay
nada que borrar, no se llena su disco y no hay riesgo de borrar un respaldo que no se verificó. Las
verificaciones se hacen en local sobre el archivo recibido: `gzip -t`, la última línea es
`-- Dump completed`, tamaño mínimo y al menos 10 `CREATE TABLE`. Si falla alguna, se borra el archivo
parcial y el script sale con error. Avisa cuando el último respaldo local tenga más de 7 días. El
destino por defecto está fuera del repositorio (`~/Backups/flow-balance`).
`flowbalance-dump` es la única definición del volcado (`mariadb-dump --single-transaction --quick
--default-character-set=utf8mb4` con `pipefail`, `.env` leído sin ejecutarlo, contraseña solo en el
entorno del proceso); lo usan este script y `release.sh` (9.3). Los respaldos previos a cada migración
siguen en `shared/backups` (los 7 más recientes) porque el pipeline los necesita para revertir.
`deploy/restore-local.sh` restaura el último respaldo en una base desechable de la MariaDB local y
compara las tablas: un respaldo que nunca se restauró es una suposición.
*Riesgo aceptado:* sin cron, el respaldo depende de que el operador lo ejecute; un fallo del servidor
entre dos ejecuciones pierde los cobros de ese intervalo. Además, `innodb_flush_log_at_trx_commit = 2`
y `skip-log-bin` (configuración de monoforms, global y no aislable por base) permiten perder hasta ~1
s de transacciones confirmadas ante una caída del sistema; se documenta y no se cambia.
*Alternativas descartadas:* crear el archivo en el servidor, copiarlo y borrarlo (exige comparar
`sha256` en ambos lados y ofrece lo mismo); cron en el servidor (el usuario prefirió el control manual).

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

- [El vhost de Flow depende de `real_ip` definido en el archivo del blog] → si alguien lo quita,
  el limitador de login vería IPs de Cloudflare y bloquearía a visitantes distintos a la vez. Se
  mitiga con la prueba de la tarea 7.5 y con la comprobación de `verificar-servidor.sh`.
- [Volver a ejecutar el Ansible de monoforms revierte o pisa lo de Flow] → no toca los archivos de
  Flow (el vhost y el pool no están en sus plantillas), pero reescribe `sshd_config` y reinicia
  servicios: se ejecuta con etiquetas y se vuelve a correr `verificar-servidor.sh` después.
- [`sshd_config.d/` se ignora en el servidor] → la plantilla de monoforms reemplaza `sshd_config` sin
  `Include`, por lo que el endurecimiento de la tarea 8.3 se escribe en `/etc/ssh/sshd_config` y se
  replica en esa plantilla para que sobreviva.
- [Desactivar root rompe el Ansible y los scripts de monoforms] → entran como `root`
  (`remote_user = root`); se ajusta su inventario antes de cerrar root.
- [El presupuesto de memoria de monoforms ya llega a ~946 MB en el pico] → el pool de Flow añade
  hasta 2 procesos; OPcache es compartido. La medición de la tarea 10.3 decide.
- [El origen acepta conexiones directas en 80 y 443 sin pasar por Cloudflare] → no se restringe
  porque afectaría al blog y queda fuera de alcance; `real_ip` ignora encabezados falsificados, y
  la app no depende de que el tráfico llegue por Cloudflare.

## Migration Plan

1. Rama `feat/despliegue-mariadb-vps`. Capturar referencias de caracterización sobre PostgreSQL
   (D11) y cerrar el trabajo de esquema y SQL con la suite completa verde en MariaDB 10.11 local.
2. PHP 8.3, lock regenerado y `APP_ENV=production` con fallos seguros.
3. Archivos de `deploy/` (Nginx, FPM, `.env.example` de producción) y el script de verificación.
4. Verificar el servidor con `verificar-servidor.sh`; aplicar sus pendientes (base, usuario,
   pool, usuario de sistema y registro DNS en Cloudflare; el certificado comodín ya existe) de forma
   manual y documentada.
5. Primer despliegue contra un dominio provisional con la base ya creada; recorrer el flujo
   completo (registro, cliente, proyecto, cobro MXN y USD, reporte, cierre de sesión).
6. Fijar el subdominio definitivo en `APP_ORIGIN`, crear su registro DNS con proxy y desplegar.

Reversión: cambiar el enlace `current` al release anterior (inmediato, sin reconstruir). Si
hubo migraciones, restaurar el respaldo previo a esa migración. El blog nunca se detiene en
ninguno de los casos.

## Open Questions

- Subdominio definitivo de Flow Balance. Solo bloquea el registro DNS y el valor de
  `APP_ORIGIN` del primer despliegue real, no el trabajo previo.
- Si Playwright se ejecuta también en CI o solo localmente. No altera el diseño; se decide al
  escribir `ci.yml`.
