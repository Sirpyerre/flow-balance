# Despliegue de Flow Balance en el VPS

Flow Balance comparte servidor con el blog Drupal de monoforms.com (Ubuntu 24.04, 961 MB de RAM, Nginx 1.24, PHP 8.3 FPM
y MariaDB 10.11). Vive aparte: su propio usuario, directorio, pool de PHP-FPM, vhost y base de datos. El
aprovisionamiento se hace **una sola vez** con un playbook de Ansible propio de este proyecto; los despliegues
siguientes los hace el pipeline.

```
 Navegador --HTTPS--> Cloudflare --HTTPS (Full strict)--> Nginx (vhost propio)
                                                           |
                         /api/*                            |   todo lo demás
                           v                               v
              PHP-FPM, pool "flowbalance"        /var/www/flowbalance/current/frontend/dist  (estático)
              (usuario flowdeploy)
              public/index.php (Mezzio)
                           |
                           v
                  MariaDB 127.0.0.1 (base flow_balance)
```

> **No use el Ansible de monoforms para esto.** Reconstruye el servidor completo (actualiza paquetes, reinicia Nginx,
> PHP-FPM y MariaDB, reescribe `nginx.conf` y `sshd_config`) y afectaría al blog. El playbook de esta carpeta solo toca
> lo de Flow Balance.

## Qué hay en `deploy/`

| Archivo | Para qué sirve |
|---|---|
| `../.github/workflows/ci.yml`, `deploy.yml` | Pruebas, y construcción y despliegue a producción |
| `ansible/flow-balance.yml` | El aprovisionamiento: usuario, directorios, base, `.env`, pool, vhost y herramienta de volcado |
| `ansible/inventory.example.yml`, `ansible/ansible.cfg` | Inventario de ejemplo (host y usuario salen del entorno) y configuración que mantiene activa la verificación del host SSH |
| `nginx/flow-balance.conf.template` | El vhost, con `@FLOW_HOST@` (lo sustituye el playbook) |
| `nginx/flow-balance-security.conf` | Cabeceras de seguridad que incluye cada `location` |
| `php-fpm/flowbalance.conf` | El pool de PHP-FPM |
| `env.production.example` | Plantilla del `shared/.env` (sin valores reales) |
| `bin/flowbalance-dump` | El volcado de la base, instalado en `/usr/local/bin` |
| `backup-local.sh`, `restore-local.sh` | Respaldo y comprobación de restauración, desde su Mac |
| `package.sh` | Arma el paquete desde una lista blanca y falla si aparece una ruta prohibida |
| `push.sh` | Verifica la huella del servidor, sube el paquete y activa el release (lo usa el pipeline) |
| `release.sh` | Respaldo, migración, activación atómica, salud y reversión; corre en el servidor, enviado por stdin |

Disposición en el servidor (la que usan Nginx y los despliegues):

```
/var/www/flowbalance/                 flowdeploy, 755
  releases/<fecha>-<sha>/             public/ src/ config/ db/migrations/ vendor/ frontend/dist/ .env -> ../../shared/.env
  current -> releases/...             enlace al release activo
  shared/.env                         secretos, modo 600
  shared/backups/                     respaldos previos a cada migración, los 7 más recientes (modo 700)
```

## 0. Antes de empezar

Corra la verificación de solo lectura y resuelva las fallas (no modifica nada):

```
ssh admin@host 'bash -s' < openspec/changes/despliegue-produccion-mariadb/verificar-servidor.sh
```

Decida el subdominio (en estos ejemplos `flow.monoforms.com`): es el único dato que necesita el playbook.

## 1. Aprovisionamiento con Ansible

Desde su Mac (probado con ansible-core 2.21; el servidor no necesita nada extra, solo Python 3 que Ubuntu ya trae).

```
cd deploy/ansible
export FLOW_VPS_HOST=<ip-o-dns-del-vps>
export FLOW_VPS_USER=root                    # o su usuario administrador con sudo (sección 10)
ssh "$FLOW_VPS_USER@$FLOW_VPS_HOST" true     # fija la huella del servidor; el playbook nunca la desactiva

# 1) Ensayo: no modifica nada (la parte de MariaDB no se simula, solo se informa)
ansible-playbook -i inventory.example.yml flow-balance.yml --check \
  -e flow_host=flow.monoforms.com -e flow_admin_public_key_file=~/.ssh/id_ed25519.pub

# 2) Aplicar
ansible-playbook -i inventory.example.yml flow-balance.yml \
  -e flow_host=flow.monoforms.com -e flow_admin_public_key_file=~/.ssh/id_ed25519.pub
```

| Variable | Para qué sirve |
|---|---|
| `flow_host` | **Obligatoria.** Subdominio en minúsculas, sin esquema ni barra |
| `flow_admin_public_key_file` | Su llave pública: la que entra como `flowdeploy` para el respaldo local |
| `flow_ci_public_key_file` | La llave pública del pipeline (tarea 9.5); se instala con la opción `restrict` |
| `flow_allow_missing_real_ip=true` | Solo para servidores de prueba sin el `real_ip` del blog |

**Antes de modificar nada (preflight)** el playbook aborta si: falta el subdominio o es inválido; las llaves traen
opciones delante; faltan Nginx o PHP 8.3-FPM; falta el certificado de origen o su llave (así se confirma la ruta de
`ssl_certificate_key`); `nginx -t` ya falla; la configuración no incluye `sites-enabled` ni el `real_ip_header
CF-Connecting-IP` del blog; o no hay acceso administrativo a MariaDB (por socket o con `/root/.my.cnf`).

**Qué hace** (y nada más): crea el usuario `flowdeploy` y los directorios; crea la base `flow_balance` y el usuario
`flow_balance_app`; genera el `.env`; instala el pool, el vhost y `/usr/local/bin/flowbalance-dump`; valida con
`nginx -t` y `php-fpm8.3 -t` y **solo recarga**. Si la validación falla, deja el servidor como estaba (restaura o
retira lo instalado), no recarga nada y el blog sigue sirviendo.

**Qué nunca toca:** `nginx.conf`, `php.ini`, el pool `www`, `sshd`, UFW, fail2ban, la base `drupal`; no actualiza
paquetes ni reinicia servicios.

**Es idempotente:** una segunda ejecución informa `changed=0`. Si alguien cambia a mano la herramienta de volcado, el
vhost o el pool, volver a ejecutarlo los repara.

Después de aplicarlo, repita la verificación de la sección 0: debe quedar sin fallas.

## 2. Base de datos y usuarios

El playbook crea `flow_balance` (utf8mb4) y el usuario `flow_balance_app` para `localhost` **y** `127.0.0.1` (son
anfitriones distintos para MariaDB cuando resuelve nombres de forma estricta, como en este servidor) con privilegios
solo sobre esa base. Si el usuario ya tiene privilegios de otra base, el playbook se detiene y lo avisa en lugar de
revocarlos en silencio. El esquema no se crea aquí: lo migra el primer despliegue.

**El `_` es un comodín en `GRANT`.** Con `ON flow_balance.*` el privilegio también alcanza a una base llamada
`flowXbalance`; hay que escribirlo `ON \`flow\_balance\`.*`. El playbook lo hace así y `verificar-servidor.sh` avisa si
lo encuentra sin escapar.

Equivalente manual (la contraseña se escribe dentro del cliente, no en la línea de comandos):

```
sudo mariadb
```
```sql
CREATE DATABASE flow_balance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'flow_balance_app'@'localhost' IDENTIFIED BY '<contraseña>';
CREATE USER 'flow_balance_app'@'127.0.0.1' IDENTIFIED BY '<contraseña>';
GRANT ALL PRIVILEGES ON `flow\_balance`.* TO 'flow_balance_app'@'localhost';
GRANT ALL PRIVILEGES ON `flow\_balance`.* TO 'flow_balance_app'@'127.0.0.1';
```

## 3. Secretos de la aplicación

Solo existen en el servidor; ni GitHub ni su Mac los ven. Si `shared/.env` no existe, el **servidor** genera
`APP_SECRET` (64 caracteres) y `DB_PASSWORD` con `openssl`, escribe el archivo en modo 600 (dueño `flowdeploy`) y deja
el usuario de MariaDB con esa contraseña. Si ya existe, **no se sobrescribe ni se rota**: el playbook solo lee
`DB_PASSWORD` para comprobar que el usuario de MariaDB la tenga, y la alinea si alguien la cambió en la base. La salida
del playbook no imprime ningún valor.

- `APP_ORIGIN` sale de `flow_host`. Si el `.env` ya existía con otro origen, el playbook avisa y no lo cambia.
- `DB_PASSWORD` debe ser alfanumérica de 16 caracteres o más (la generada lo es); con otros caracteres el playbook se
  detiene, sin imprimirla.
- Con menos de 32 caracteres en `APP_SECRET` el inicio de sesión responde 503.

Equivalente manual: `sudo install -m 600 -o flowdeploy -g flowdeploy deploy/env.production.example
/var/www/flowbalance/shared/.env` y completar `APP_ORIGIN`, `APP_SECRET` (`openssl rand -hex 32`) y `DB_PASSWORD`.

## 4. PHP-FPM y Nginx

Comparten el proceso maestro con el blog. El playbook valida y recarga (las peticiones en curso terminan), pero hágalo
una vez y fuera de horas pico. Instala:

- `/etc/php/8.3/fpm/pool.d/flowbalance.conf` (usuario `flowdeploy`, socket `/run/php/flowbalance.sock`).
- `/etc/nginx/snippets/flow-balance-security.conf` y `/etc/nginx/sites-available/flow-balance.conf` (con el enlace en
  `sites-enabled`).

El certificado es el comodín de origen de Cloudflare (`*.monoforms.com`), vigente hasta 2041: no hace falta certbot ni
ningún token de la API de Cloudflare en el servidor. El playbook comprueba que el par
`/etc/nginx/ssl/cloudflare-origin.pem` y `.key` exista; si el blog usa otra ruta, corríjala en
`deploy/nginx/flow-balance.conf.template`.

El vhost **no** repite `set_real_ip_from` ni `real_ip_header`: los hereda de `monoforms.conf`, donde están a nivel
`http`; el playbook se niega a continuar si no están. Brotli tampoco se repite: el blog lo activa a nivel `http`
(`brotli on`) y el vhost lo hereda; el vhost solo declara gzip como respaldo.

Equivalente manual:

```
sudo install -m 644 deploy/php-fpm/flowbalance.conf /etc/php/8.3/fpm/pool.d/flowbalance.conf
sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm
sudo install -m 644 deploy/nginx/flow-balance-security.conf /etc/nginx/snippets/flow-balance-security.conf
sed 's/@FLOW_HOST@/flow.monoforms.com/g' deploy/nginx/flow-balance.conf.template | sudo tee /etc/nginx/sites-available/flow-balance.conf >/dev/null
sudo ln -s /etc/nginx/sites-available/flow-balance.conf /etc/nginx/sites-enabled/flow-balance.conf
sudo nginx -t && sudo systemctl reload nginx
```

## 5. Cloudflare (lo hace usted en el panel)

1. **DNS:** registro del subdominio con proxy activado (nube naranja).
2. **SSL/TLS:** modo **Full (strict)**.
3. **Caché:** una regla (Cache Rules) para ese host con la condición *la ruta no empieza por `/_astro/`* y la acción
   **Bypass cache**. Las vistas autenticadas y la API ya llevan `private, no-store`; la regla evita además que se
   guarde el HTML público.
4. **Optimización:** desactive para ese host Rocket Loader, Auto Minify (si aún aparece) y Email Obfuscation (Configuration
   Rules): reescriben HTML y JavaScript y pueden romper las islas de React y el service worker.
5. Opcional: regla de límite de tasa sobre `POST /api/auth/login`.

Comprobación (debe mostrar `cf-cache-status: DYNAMIC` o `BYPASS` y `cache-control: private, no-store`):

```
curl -sI https://flow.monoforms.com/dashboard | grep -iE 'cf-cache-status|cache-control'
```

## 6. Comprobar la IP real del visitante

El limitador de inicios de sesión cuenta por IP, así que PHP debe recibir la del visitante. El registro de acceso del
vhost usa el formato `combined`, cuyo primer campo es esa dirección.

```
# a) Por Cloudflare: la primera columna debe ser SU IP pública, no una de Cloudflare (104.x, 172.64.x...).
curl -s -o /dev/null https://flow.monoforms.com/login
sudo tail -1 /var/log/nginx/flow-balance.access.log

# b) Directo al origen, con una cabecera falsa: la primera columna debe ser SU IP, no 203.0.113.9.
curl -sk --resolve flow.monoforms.com:443:<IP-DEL-VPS> -H 'CF-Connecting-IP: 203.0.113.9' -o /dev/null https://flow.monoforms.com/login
sudo tail -1 /var/log/nginx/flow-balance.access.log
```

## 7. Respaldo y restauración

El respaldo es de la base de Flow Balance solamente, **manual y desde su Mac**. El volcado viaja comprimido por SSH
directo a su disco: el servidor no guarda ninguna copia, así que no hay nada que borrar ni que llene su disco.

```
FLOW_BACKUP_HOST=flowdeploy@<host> deploy/backup-local.sh        # respalda en ~/Backups/flow-balance (modo 700)
deploy/backup-local.sh --status                                  # ¿qué tan viejo es el último?
```

Antes de aceptar el archivo lo verifica: tamaño mínimo, `gzip -t`, última línea `-- Dump completed` y al menos 10
`CREATE TABLE`. Si algo falla (credenciales, volcado vacío o truncado, conexión cortada) no queda ningún archivo y el
código de salida es distinto de cero. Avisa si el respaldo anterior tiene más de 7 días. Variables: `FLOW_BACKUP_DIR`,
`FLOW_BACKUP_SSH_OPTS`, `FLOW_BACKUP_MIN_BYTES`, `FLOW_BACKUP_EXPECTED_TABLES`, `FLOW_BACKUP_STALE_DAYS`.

**Un respaldo que nunca se restauró es una suposición.** Compruebe de vez en cuando que se restaura:

```
deploy/restore-local.sh                          # el último respaldo, en una base desechable de su MariaDB local
deploy/restore-local.sh archivo.sql.gz --contra <base-local>    # además compara tabla por tabla (CHECKSUM TABLE)
```

Usa el servicio `mariadb` del `compose.yaml` y la `MARIADB_ROOT_PASSWORD` de su `.env`; la base desechable se elimina
siempre. Sin `--contra` (el caso normal: la base de producción no es accesible desde su Mac) exige que se restauren
sin errores las tablas y restricciones `CHECK` del esquema y muestra las filas de cada tabla.

Los respaldos **previos a cada migración** los hace el pipeline en el servidor (`shared/backups`, los 7 más recientes)
para poder revertir una migración fallida; son independientes de los de esta sección.

Restaurar en producción (emergencia; sustituye los datos actuales): primero compruebe el archivo con
`restore-local.sh`, y después `gzip -dc <archivo> | ssh <admin>@<host> 'sudo mariadb --default-character-set=utf8mb4 flow_balance'`.
El volcado trae `DROP TABLE IF EXISTS` de cada tabla.

## 8. Reversión manual

Volver al release anterior es cambiar el enlace; no reconstruye nada y no toca el blog:

```
ls /var/www/flowbalance/releases
ln -s releases/<release-anterior> /var/www/flowbalance/current.tmp && mv -T /var/www/flowbalance/current.tmp /var/www/flowbalance/current
```

Si el release nuevo aplicó migraciones, restaure también el respaldo previo a esa migración (sección 7).

## 9. Cuando algo falla

| Síntoma | Dónde mirar |
|---|---|
| El playbook aborta en el preflight | El mensaje dice qué falta; no se modificó nada |
| El playbook aborta con «`nginx -t` rechazó…» o «`php-fpm8.3 -t` rechazó…» | Ya restauró el estado anterior y no recargó; el mensaje trae el error de Nginx o PHP-FPM |
| 502 en `/api/` | `systemctl status php8.3-fpm`; el socket `/run/php/flowbalance.sock` existe y es de `www-data:www-data` |
| 503 `configuration_unavailable` | `APP_ORIGIN` o `APP_SECRET` inválidos en `shared/.env`; `/var/log/php8.3-fpm.log` nombra el ajuste, nunca su valor |
| 503 `persistence_unavailable` | MariaDB activo, usuario y contraseña de `shared/.env`, `max_connections` |
| Código viejo tras un despliegue | Nginx debe usar `$realpath_root` (así viene en la plantilla); no hace falta recargar PHP-FPM |
| Errores de Nginx | `/var/log/nginx/flow-balance.error.log` |
| `WARNING: [pool flowbalance] server reached max_children (2)` en el registro de PHP-FPM | Hay peticiones en cola: es el límite deliberado de 2 procesos. Si es frecuente, revise qué las demora antes de subirlo; el servidor tiene 961 MB |

## 10. Acceso de administración (tarea 8.3)

Cree su usuario sin root, con sudo y llave, entre con él **en una segunda sesión** y solo entonces desactive el acceso
de root. Si cierra la sesión actual antes de probar, puede quedarse fuera. Este usuario no es `flowdeploy`, que no lleva
sudo.

Dos particularidades de este servidor, que el Ansible de monoforms configura:

- **`/etc/ssh/sshd_config.d/` se ignora:** su `sshd_config` no trae `Include`. Escriba `PermitRootLogin no` en
  `/etc/ssh/sshd_config` y replíquelo en la plantilla `sshd_config.j2` de monoforms; si no, una nueva ejecución de ese
  Ansible lo revierte (hoy está en `prohibit-password`; la contraseña ya está desactivada).
- **Ese Ansible, Terraform y sus scripts entran como `root`** (`remote_user = root`). Cámbielo a su usuario nuevo antes
  de cerrar root, o dejarán de funcionar.

Luego use ese usuario como `FLOW_VPS_USER` en la sección 1 (el playbook necesita sudo, no root).

## 11. Riesgos aceptados

- **Un solo usuario (`flowdeploy`) ejecuta PHP y es dueño del código.** Si PHP se compromete, el atacante puede reescribir
  el release y leer `shared/.env`. Lo acotan `open_basedir`, `disable_functions`, el pool de 2 procesos y que no es el
  usuario del blog.
- **Durabilidad:** MariaDB corre con `innodb_flush_log_at_trx_commit = 2` y `skip-log-bin` (configuración de monoforms,
  global y no aislable por base). Una caída del sistema entero (no solo de MariaDB) puede perder hasta ~1 s de
  transacciones confirmadas y no hay recuperación a un punto en el tiempo; el respaldo es lo único que protege.
- **Respaldo manual:** depende de que usted lo ejecute. Un fallo del servidor entre dos respaldos pierde los cobros de ese
  intervalo; `backup-local.sh --status` le dice cuánto hace del último.

## 12. Pipeline de despliegue (GitHub Actions)

```
 push a main / manual
        |
   ci.yml (PHP 8.3 y 8.4 + MariaDB 10.11, Astro)  --falla-->  no se sube nada
        |
   build: composer --no-dev, astro build con APP_ORIGIN, package.sh (lista blanca)    [sin secretos]
        |
   deploy (entorno "production", con revisores): push.sh
        |-- verifica la huella del servidor (si no coincide, aborta sin transferir)
        |-- rsync del paquete a releases/<fecha>-<sha>/
        '-- release.sh (por stdin, como flowdeploy): enlaza .env -> respalda y VERIFICA -> migra
                       -> cambia "current" (mv -T) -> /api/health por loopback -> si falla, devuelve el enlace
```

Cada despliegue deja el release activo y los 3 más recientes, y los 7 respaldos previos a migración más recientes.

**Configuración en GitHub** (Settings, una sola vez):

1. *Environments > New environment > `production`*: *Required reviewers* (usted) y *Deployment branches: Selected
   branches > `main`*. Los secretos y variables siguientes se crean **en ese entorno**, no en el repositorio.
2. **Secreto** `DEPLOY_SSH_KEY`: la llave privada de despliegue (siguiente punto).
3. **Variables** (no sensibles): `DEPLOY_HOST` (IP o DNS del VPS), `DEPLOY_USER` (`flowdeploy`), `APP_ORIGIN`
   (`https://<subdominio>`, se compila dentro del sitio) y `DEPLOY_KNOWN_HOSTS` (la huella fijada del servidor).
4. Los secretos de la aplicación (`APP_SECRET`, `DB_PASSWORD`) **no** se cargan en GitHub: viven solo en
   `shared/.env`.

**Llave de despliegue exclusiva** (distinta de su llave personal):

```
ssh-keygen -t ed25519 -N '' -f ~/.ssh/flow_deploy -C github-actions-flow-balance
# instalarla en el servidor con la opción `restrict` (sin pty, sin reenvíos): la pasa el playbook
cd deploy/ansible && ansible-playbook -i inventory.example.yml flow-balance.yml \
  -e flow_host=<subdominio> -e flow_admin_public_key_file=~/.ssh/id_ed25519.pub \
  -e flow_ci_public_key_file=~/.ssh/flow_deploy.pub
gh secret set DEPLOY_SSH_KEY --env production < ~/.ssh/flow_deploy      # o pegue el archivo en la interfaz
rm -P ~/.ssh/flow_deploy                                                  # la privada ya no hace falta en su Mac
```

**Huella del servidor** (`DEPLOY_KNOWN_HOSTS`): obténgala por un canal de confianza, no desde el propio runner.

```
ssh-keyscan -t ed25519 <host> 2>/dev/null                 # la línea para la variable
ssh-keygen -lf <(ssh-keyscan -t ed25519 <host> 2>/dev/null)   # compare esta huella con la del servidor:
ssh <admin>@<host> 'ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub'
```

Si las dos huellas no coinciden, no despliegue: alguien está en medio.

**Códigos de `release.sh`** (aparecen en el registro del trabajo): `10` precondición (falta el paquete, el `.env` o la
herramienta de volcado), `20` el respaldo falló o quedó vacío (no se migró nada), `30` la migración falló (el enlace
no se tocó), `40` la salud falló y se devolvió el enlace, `41` la salud falló y no se pudo devolver, `50` hay otro
despliegue en curso.

**Una migración que falla puede dejar el esquema a medias** (el DDL de MariaDB no es transaccional). `release.sh` no la
revierte: deja el enlace anterior y el respaldo previo en `shared/backups`. Las migraciones del proyecto deben poder
reintentarse. Lo mismo si la salud falla después de migrar: el código vuelve, la base conserva el esquema nuevo, por
eso las migraciones deben ser compatibles con el release anterior.

**Probar el pipeline a mano** (sin GitHub), con una llave y una huella:

```
DEPLOY_HOST=<host> DEPLOY_USER=flowdeploy DEPLOY_SSH_KEY_FILE=~/.ssh/flow_deploy \
DEPLOY_KNOWN_HOSTS="$(grep <host> ~/.ssh/known_hosts)" deploy/push.sh build/package <release> <subdominio>
```
