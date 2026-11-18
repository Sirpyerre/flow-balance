# Guía de ejecución y validación local

Esta guía describe los comandos que estarán disponibles al implementar el plan.
Actualmente no existen `composer.json`, `compose.yaml` ni el frontend: esta entrega
no ejecuta estos ejemplos ni acredita sus resultados. Ejecutarlos desde la raíz
del proyecto una vez generados e implementados los artefactos correspondientes.

## 1. Requisitos y configuración

Herd con PHP 8.4, Composer 2, Node 22.23 o superior compatible con Astro, Docker
Desktop/Compose y navegador con confianza en el certificado HTTPS de Herd.
Extensiones PHP: `pdo_pgsql`, `mbstring`; `PASSWORD_ARGON2ID` disponible.

```sh
cd /Users/peter/Herd/flow_balance
flowBalancePhp='/Users/peter/Library/Application Support/Herd/bin/php84'
flowBalanceComposer='/Users/peter/Library/Application Support/Herd/bin/composer'
herd isolate 8.4
herd php -v
herd composer install
npm --prefix frontend ci
cp .env.example .env
```

Completar `.env` con `APP_ORIGIN=https://flow-balance.test`, `APP_ENV=development`,
`DB_HOST=127.0.0.1`, `DB_PORT=54329`, `DB_DATABASE=flow_balance`,
`DB_USERNAME=flow_balance_app` y contraseña local. Añadir `TEST_DB_DATABASE=flow_balance_test`,
`TEST_DB_USERNAME=flow_balance_test_app`, su propia contraseña, host/puerto y secreto
independiente para pruebas. Configurar secreto de limitación mediante variable privada.
No usar prefijos `PUBLIC_` en ninguna credencial. No commitear `.env`.

El proveedor `DatabaseConfig` debe seleccionar variables `TEST_DB_*` únicamente con
`APP_ENV=testing`, tanto para PHPUnit como para Phinx `-e testing`; abortar si faltan.
`phinx.php` rechaza una discrepancia entre ambiente CLI y APP_ENV para evitar migrar
otra base por accidente. Desarrollo usa `APP_ENV=development` y `-e development`.

Las variables anteriores se usan siempre en los ejemplos de pruebas. Si el wrapper
de Herd falla, los siguientes comandos usan directamente su PHP:

```sh
flowBalancePhp='/Users/peter/Library/Application Support/Herd/bin/php84'
flowBalanceComposer='/Users/peter/Library/Application Support/Herd/bin/composer'
"$flowBalancePhp" -v
"$flowBalancePhp" "$flowBalanceComposer" install
"$flowBalancePhp" -r 'echo implode(",", password_algos()), PHP_EOL;'
```

El wrapper falló en la inspección de este equipo; el binario explícito reportó
PHP 8.4.16 con Argon2id y `pdo_pgsql`. Usar el patch actualizado de PHP 8.4 al implementar.
`@php` en scripts Composer conserva el intérprete que ejecutó Composer.

## 2. PostgreSQL y ambientes separados

```sh
docker compose up -d postgres
docker compose ps postgres
```

Resultado esperado: servicio sano con volumen persistente, PostgreSQL 17 en
`127.0.0.1:54329`, bases `flow_balance` y `flow_balance_test` y roles separados.
El bootstrap crea bases/roles y restringe CONNECT: la credencial de pruebas no puede
conectarse a desarrollo y viceversa. Phinx crea las tablas de aplicación.

Los scripts `db/bootstrap` solo corren automáticamente con volumen vacío. Si ya hay
volumen, verificar/aprovisionar las bases y roles sin borrar el volumen ni sus datos.
Las comprobaciones de conexión muestran nombres y usuario, nunca contraseñas.

## 3. Comandos Composer de migración

El `composer.json` de implementación debe exponer estos scripts, sin ocultar los
parámetros de ambiente:

| Script | Comando que ejecuta |
|---|---|
| `db:create-migration` | `@php vendor/bin/phinx create -c phinx.php` |
| `db:migrate` | `@php vendor/bin/phinx migrate -c phinx.php` |
| `db:status` | `@php vendor/bin/phinx status -c phinx.php` |
| `db:rollback` | `@php vendor/bin/phinx rollback -c phinx.php` |
| `test:unit` | `@php vendor/bin/phpunit --testsuite Unit` |
| `test:integration` | `@php vendor/bin/phpunit --testsuite Integration` |

Instalación prevista de Phinx si se agrega por primera vez:

```sh
herd composer require robmorgan/phinx
```

Ejemplo para crear la primera migración durante la implementación; si `CreateUsers`
ya existe, no crear otra con el mismo propósito:

```sh
herd composer db:create-migration -- CreateUsers
```

Aplicar y consultar usando el PHP de Herd:

```sh
APP_ENV=development herd composer db:migrate -- -e development
APP_ENV=development herd composer db:status -- -e development
APP_ENV=development herd composer db:migrate -- -e development
```

La primera ejecución crea el esquema y registra las versiones; la segunda ejecución
de migrate no repite cambios ni altera filas. Comparar estado y número de versiones.
Con el binario explícito, el equivalente para pruebas es:

```sh
APP_ENV=testing "$flowBalancePhp" "$flowBalanceComposer" db:migrate -- -e testing
APP_ENV=testing "$flowBalancePhp" "$flowBalanceComposer" db:status -- -e testing
```

No usar una base con datos valiosos para ensayar reversiones. Solo en la base de
pruebas desechable vacía, antes de cargar fixtures:

```sh
APP_ENV=testing "$flowBalancePhp" "$flowBalanceComposer" db:rollback -- -e testing
APP_ENV=testing "$flowBalancePhp" "$flowBalanceComposer" db:migrate -- -e testing
```

`rollback` sin target revierte una migración, la última aplicada según el historial.
No pasar `-t 0`: eso revierte todas. Verificar que la última baja y luego sube, y que
el esquema coincide. Si una tabla contiene registros, el rollback destructivo debe
abortar salvo procedimiento de preservación. Recrearla vacía no recupera datos.

## 4. Servir interfaz y API bajo el mismo origen

Enlazar como sitio PHP el document root de Mezzio y crear el proxy público:

```sh
cd /Users/peter/Herd/flow_balance/public
herd link flow-balance-api
herd isolate 8.4
cd /Users/peter/Herd/flow_balance
herd proxy flow-balance http://127.0.0.1:4321
herd secure flow-balance
npm --prefix frontend run dev
```

Abrir `https://flow-balance.test`. El sitio interno `flow-balance-api.test` usa
PHP de Herd; Astro enruta `/api` según [el contrato de desarrollo](contracts/development-routing.md).
Verificar el virtual host y que `/api/auth/csrf` llega a `public/index.php`, sin
eliminar el prefijo. No crear un segundo backend Node ni habilitar CORS para resolverlo.

Comprobaciones en el navegador:

1. La URL pública es HTTPS y todas las llamadas usan `/api/...` en ese origen.
2. La cookie es HttpOnly/Secure/SameSite=Lax/Path=/, sin Domain, con nombre `__Host-flow_balance`.
3. Los `Set-Cookie`, estados y cabeceras no-store llegan sin cambios incorrectos.
4. HMR conecta por WSS, y falsificar forwarding no cambia la IP efectiva del limitador.
5. La cuenta se registra y accede; no hay errores por CSRF, certificado o mixed content.

Para acceder desde un celular físico, resolver el host público hacia la Mac y confiar
en el certificado local en ese dispositivo; no cambiar a HTTP ni desactivar Secure.
La exposición de red local no convierte el PostgreSQL de loopback en un servicio público.

## 5. Pruebas automatizadas en base independiente

La suite verifica antes de cualquier cambio destructivo: APP_ENV=testing, base con
nombre exacto `flow_balance_test`, rol de pruebas y falta de acceso a desarrollo.
No aceptar solo un sufijo como única defensa. Unit usa reloj/repositorios de prueba;
Integration usa PostgreSQL y el pipeline de producción sin sustituir autorización.

```sh
APP_ENV=testing "$flowBalancePhp" "$flowBalanceComposer" test:unit
APP_ENV=testing "$flowBalancePhp" "$flowBalanceComposer" test:integration
```

La integración debe probar: esquema vacío → migrate → registro con CSRF → segundo
migrate sin cambios; migración reversible; duplicados concurrentes y FK compuestas;
hashing sin truncamiento; sesión regenerada, revocada y vencida; límites concurrentes;
CSRF y aislamiento del reporte/modificación. El bootstrap de tests no incluye
credenciales de desarrollo. Nunca ejecuta `down -v` sobre la instancia local.

Para E2E, levantar procesos separados con configuración testing, no hacer que el
frontend de pruebas llame al backend de desarrollo. Backend de prueba usa el binario
PHP de Herd con servidor CLI solo para la suite; el desarrollo ordinario usa Herd PHP-FPM.
En una terminal:

```sh
APP_ENV=testing APP_ORIGIN=https://flow-balance-test.test "$flowBalancePhp" -S 127.0.0.1:8081 -t public public/index.php
```

En otra terminal:

```sh
herd proxy flow-balance-test http://127.0.0.1:4322
herd secure flow-balance-test
APP_ORIGIN=https://flow-balance-test.test API_PROXY_TARGET=http://127.0.0.1:8081 npm --prefix frontend run dev -- --port 4322
```

Ejecutar la suite de navegador prevista por el plan:

```sh
APP_ENV=testing TEST_BASE_URL=https://flow-balance-test.test npm --prefix frontend run test:e2e
```

La configuración de Astro debe leer las dos variables del proceso para el proxy y
host permitido. El servidor CLI nunca se usa como servidor productivo. Las dos bases,
secretos y procesos permanecen separados. Las pruebas de concurrencia del servidor
se realizan también con procesos PHP múltiples o integración directa, no se pretende
acreditarlas con un único worker del servidor CLI.

## 6. Recorridos de aceptación

### Base vacía y cuenta

Aplicar las migraciones en testing, obtener CSRF y registrar nombre, correo,
contraseña/confirmación válidos. Esperar 201, una fila en users y entrada automática
al tablero vacío con “Crear mi primer proyecto”. Verificar que solo existe hash y
que el identificador anterior no autentica. Variantes de mayúsculas/espacios del
correo no crean otra cuenta, incluso al registrar simultáneamente.

### Dos cuentas y modificación ajena

La suite prepara mediante servicios reales a Ana y Luis, sus clientes/proyectos y
cobros en el mismo mes: MXN 3,000.00 y MXN 800.00 sin deducciones. En sesiones
separadas consulta `/api/reports/monthly?month=2026-11&currency=MXN`: espera totales
`3000.00` y `800.00`, con trazabilidad solo a sus cobros. Luis hace GET y PATCH al
cobro de Ana con su propio token CSRF válido: espera 404, no un rechazo causado por
un CSRF ausente. Ana vuelve a consultar: valor original y totales intactos.
Intentar además una relación entre cuentas directamente en SQL: la FK la rechaza.

### Sesión, CSRF y caché

Comprobar con reloj controlado 24 horas de inactividad y 7 días absolutos; el endpoint
de estado no los prolonga. Cinco fallos activan 15 minutos de pausa aun si luego la
contraseña es correcta, y el correo inexistente se trata igual. Intentos concurrentes
no evitan el límite. Al cerrar, replay de cookie anterior es 401 y una petición en
curso no recrea la sesión. Una escritura sin token/con token ajeno/origen distinto
no aplica cambios. Verificar también dos contraseñas diferentes después del byte 72.

Desde web móvil y PWA, usar autocompletado y gestor, pegar contraseña y enviar con
Enter. Después salir, usar Atrás, otra pestaña y modo offline; entrar como otro usuario:
no aparece contenido del titular anterior. Inspeccionar Cache Storage, IndexedDB y
localStorage: ninguna respuesta ni importe financiero privado. Registro ≤2 minutos
y acceso ≤1 minuto se verifican con la prueba de usabilidad definida en la spec.

## 7. Recuperación de un cambio destructivo futuro

Antes de eliminar tablas/columnas con datos, documentar exportación y restauración
probada en otra base, o migración gradual con copia y validación. Verificar conteos,
propietarios e importes antes y después. Si no existe recuperación segura, declarar
la migración irreversible y definir restauración desde respaldo, no un down ficticio.
El historial de Phinx acredita versiones de esquema, no constituye respaldo de datos.
