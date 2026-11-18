# Investigación: autenticación y migraciones de Flow Balance

**Fecha**: 2026-11-18. Investigación documental; no instala dependencias ni ejecuta migraciones.
Las decisiones siguientes completan el contexto técnico de [la especificación](spec.md).

## 1. Plataforma y responsabilidades

**Decisión**: PHP 8.4 con `pdo_pgsql`, `mbstring` y Argon2id; Mezzio 3 para la API;
Astro estable con TypeScript y su integración React compatible; PostgreSQL 17 en
Docker Compose. Composer 2 y Node.js 22.23 o superior compatible con la versión
seleccionada de Astro. Fijar versiones exactas en los archivos de bloqueo durante
la implementación y comprobar los requisitos `engines` antes de instalarlas.

**Motivo**: PHP 8.4 instalado de Herd reporta 8.4.16, Argon2id y `pdo_pgsql`.
Node local reporta 22.23.2 y Docker Compose 2.39.1. No existe aplicación ni archivo
de dependencias previo. Son elecciones de base, no afirmaciones de versiones más recientes.
El wrapper local `herd php -v` falló; usar el binario de Herd explícito como alternativa.

**Alternativas consideradas**: API en Astro y contenedores PHP; descartadas
por las decisiones del usuario. No se agrega un segundo sistema de autenticación.

Fuentes: [PHP soportado](https://www.php.net/supported-versions.php),
[configuración Astro](https://docs.astro.build/en/reference/configuration-reference/).

## 2. Contraseñas completas

**Decisión**: `password_hash` con `PASSWORD_ARGON2ID`, `password_verify` y
`password_needs_rehash` después de verificar. El arranque comprueba soporte del
algoritmo; no hay fallback silencioso. `password_hash` se guarda en columna de 255
caracteres. Mantener 12–128 caracteres, sin recorte ni normalización de la contraseña;
validar longitud Unicode, no longitud en bytes.

**Motivo**: Evita el límite de 72 bytes de bcrypt y permite probar contraseñas que
solo difieren después de ese límite. Usar hash ficticio con el mismo coste para
un correo inexistente; errores públicos equivalentes, sin prometer tiempo idéntico.

**Alternativas consideradas**: `PASSWORD_DEFAULT` sin elegir algoritmo y hashing
propio; no cumplen el contrato o añaden criptografía innecesaria.

Fuentes: [hashing PHP](https://www.php.net/manual/en/function.password-hash.php),
[verificación](https://www.php.net/manual/en/function.password-verify.php),
[actualización del hash](https://www.php.net/manual/en/function.password-needs-rehash.php).

## 3. Sesión persistida en PostgreSQL y CSRF

**Alcance de la decisión**: La web y la PWA comparten origen con `/api` y usan
la misma sesión de servidor. Tener endpoints JSON no exige JWT. El backend valida
identidad y pertenencia en cada petición privada, y el logout revoca la sesión
en servidor. JWT exigiría diseñar además su ciclo de vida y revocación; se
reconsiderará ante consumidores externos o delegación de acceso, según sus
necesidades concretas. Se mantiene cookie HttpOnly/Secure/SameSite y protección
CSRF para escrituras.

Fuentes de esta decisión: [OWASP: gestión de sesiones](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html),
[OWASP: consideraciones sobre JWT](https://cheatsheetseries.owasp.org/cheatsheets/JSON_Web_Token_Cheat_Sheet.html#considerations-about-using-jwts).

**Decisión**: `mezzio/mezzio-session` con un adaptador `PostgresSessionPersistence`
a `SessionPersistenceInterface`; contenedores de la biblioteca y regeneración
mediante `regenerate()`. El adaptador almacena en PostgreSQL el hash SHA-256 de un
identificador aleatorio de 32 bytes; el valor original solo viaja en cookie y memoria.
`mezzio/mezzio-csrf` proporciona tokens ligados a sesión, también antes de autenticar.

**Motivo**: Una única persistencia de servidor permite revocar y probar límites sin
recolectores de archivos. El adaptador usa transacción y bloqueo de fila por sesión
hasta terminar la petición; la revocación o regeneración no puede ser sobrescrita por
otra petición en curso. Las transacciones de negocio participan en la misma unidad
de trabajo y no se anidan. El token desconocido o revocado nunca se vuelve a insertar.

**Alternativas consideradas**: JWT, almacenamiento de identidad en el navegador,
sesión de Astro y archivos de `mezzio-session-ext`. Los archivos son más simples,
pero requieren almacenamiento adicional y una política de persistencia distinta de
PostgreSQL; se elige el adaptador acotado al contrato oficial, con pruebas de concurrencia.

Fuentes: [persistencia de sesiones Mezzio](https://docs.mezzio.dev/mezzio-session/v1/persistence/),
[contenedores y regeneración](https://docs.mezzio.dev/mezzio-session/v1/session/),
[CSRF de Mezzio](https://docs.mezzio.dev/mezzio-csrf/).

## 4. Origen único y proxy local

**Decisión**: `https://flow-balance.test` en Herd hace proxy a Astro en
`http://127.0.0.1:4321`. `vite.server.proxy` envía `/api` al sitio Mezzio
`http://flow-balance-api.test`, también resuelto por Herd; mantiene `/api` y usa
`changeOrigin: true` solo para seleccionar el virtual host de destino. Las URLs
públicas siempre son relativas. El `Origin` del navegador no se reescribe.

**Motivo**: HTTPS público permite cookies Secure sin CORS entre frontend y API.
`APP_ORIGIN` es explícito; se confían solo proxies locales configurados y su cadena
verificada, no cabeceras arbitrarias del cliente. IP de conexión y cabecera `Origin`
son conceptos diferentes. Cookies sin `Domain` evitan el dominio interno del backend.

**Alternativas consideradas**: fetch directo al dominio PHP, CORS permisivo y frontend
HTTP; complican o incumplen la sesión de mismo origen. En producción el proxy de
entrada separa `/api` y archivos Astro directamente; no se ejecuta el proxy de Vite.

Fuentes: [Herd CLI](https://herd.laravel.com/docs/macos/advanced-usage/herd-cli),
[opciones del servidor Vite](https://vite.dev/config/server-options).

## 5. Normalización y pertenencia de cuenta

**Decisión**: La expresión canónica PostgreSQL es
`lower(btrim(email, chr(32) || chr(9) || chr(10) || chr(13) || chr(12) || chr(11)))`.
Se calcula en `email_normalized`, columna generada de `users` con restricción única,
y se usa también para buscar en login y obtener la clave del limitador.
El conjunto de espacios exteriores corresponde al whitespace ASCII declarado; NUL
se rechaza y no se admiten espacios internos. Base UTF-8 con locale fijo y el mismo
locale en desarrollo, pruebas y despliegue. No se fusionan alias ni puntos.

**Motivo**: No depender de diferencias entre `trim`, `strtolower`, Unicode y locale
entre PHP y PostgreSQL. La columna generada con restricción única impide duplicados
incluso con carrera de registros.
Capturar SQLSTATE `23505` únicamente de la restricción esperada para el error de
duplicado, con rollback a savepoint antes de continuar en la transacción.
Las relaciones de cuenta usan FK compuestas `(user_id, resource_id)` y consultas
filtradas por identidad; ninguna de las dos medidas sustituye a la otra.

**Alternativas consideradas**: Comprobar duplicados solo en PHP, `citext` y normalizar
independientemente en dos lenguajes; no añaden una ventaja frente a la expresión única.

Fuentes: [índices por expresión](https://www.postgresql.org/docs/17/indexes-expressional.html),
[cadenas PostgreSQL](https://www.postgresql.org/docs/17/functions-string.html),
[restricciones](https://www.postgresql.org/docs/17/ddl-constraints.html),
[columnas generadas](https://www.postgresql.org/docs/17/ddl-generated-columns.html),
[savepoints](https://www.postgresql.org/docs/17/sql-savepoint.html).

## 6. Phinx y separación de ambientes

**Decisión**: `robmorgan/phinx` instalado con Composer como dependencia disponible
al desplegar migraciones. `phinx.php` reutiliza `DatabaseConfig` y la fábrica PDO de
la aplicación; proporciona `connection` y `name`. `db/migrations` y tabla `phinxlog`;
`version_order=execution`. Los scripts usan `@php vendor/bin/phinx`.

**Motivo**: Evita credenciales duplicadas y revierte la última migración realmente
aplicada. `rollback` sin target revierte una; `-t 0` revierte todas y no se usa como
comando ordinario. Pruebas con base y rol exclusivos; ningún fallback a desarrollo.

**Alternativas consideradas**: SQL manual sin historial, migraciones al arrancar cada
petición y configurar la conexión de Phinx por separado; se rechazan por reproducibilidad.

Fuentes: [configuración Phinx](https://book.cakephp.org/phinx/0/en/configuration.html),
[comandos](https://github.com/cakephp/phinx/blob/0.x/docs/en/commands.rst),
[migraciones](https://book.cakephp.org/phinx/0/en/migrations.html),
[scripts Composer](https://getcomposer.org/doc/articles/scripts.md),
[imagen oficial PostgreSQL](https://hub.docker.com/_/postgres).

## 7. Caché y pruebas mínimas reales

**Decisión**: Precache de PWA limitado a recursos estáticos públicos por lista positiva.
`/api/**`, documentos privados y respuestas de autenticación usan NetworkOnly y
`Cache-Control: no-store, private`. Datos financieros solo en memoria; limpiar al
salir, cambiar cuenta, perder validez o abandonar una página privada. Revalidar al
volver mediante el historial antes de volver a mostrar datos.

**Motivo**: Cache Storage del service worker necesita exclusión explícita; no basta
poner cabeceras HTTP. Las pruebas usan PostgreSQL real, reloj controlable y dos
contextos de navegador; los mocks no acreditan aislamiento ni restricciones SQL.

**Alternativas consideradas**: caché financiera offline, pruebas exclusivamente
unitarias y borrar la cookie sin invalidar el servidor; no cumplen los criterios.

Fuentes: [Cache API](https://developer.mozilla.org/en-US/docs/Web/API/Cache),
[Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control).

## Cierre de investigación

No quedan decisiones técnicas bloqueantes. Los parámetros de capacidad, despliegue
productivo y medición de coste Argon2id se validarán en su entorno; no alteran las
reglas de sesión, aislamiento o migraciones. El diseño posterior usa estas decisiones.
