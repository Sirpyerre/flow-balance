# Despliegue de Flow Balance en el VPS

Flow Balance comparte servidor con el blog Drupal de monoforms.com (Ubuntu 24.04, 1 vCPU, 961 MB de RAM,
Nginx 1.24, PHP 8.3 FPM y MariaDB 10.11). Vive aparte: su propio usuario, directorio, pool de PHP-FPM, vhost y base
de datos. Este documento es el aprovisionamiento que se hace **una sola vez**; los despliegues siguientes los hace
el pipeline.

```
 Navegador --HTTPS--> Cloudflare --HTTPS (Full strict)--> Nginx (vhost propio)
                                                           |
                         /api/*                            |   todo lo demás
                           v                               v
              PHP-FPM, pool "flowbalance"        /var/www/flowbalance/current/frontend/dist  (estático)
              public/index.php (Mezzio)
                           |
                           v
                  MariaDB 127.0.0.1 (base flow_balance)
```

## Qué hay en `deploy/`

| Archivo | Se instala en |
|---|---|
| `nginx/flow-balance.conf.template` | `/etc/nginx/sites-available/flow-balance.conf` (con el host ya escrito) |
| `nginx/flow-balance-security.conf` | `/etc/nginx/snippets/flow-balance-security.conf` |
| `php-fpm/flowbalance.conf` | `/etc/php/8.3/fpm/pool.d/flowbalance.conf` |
| `env.production.example` | `/var/www/flowbalance/shared/.env`, completado en el servidor |

Disposición en el servidor (la que usan Nginx y los despliegues):

```
/var/www/flowbalance/
  releases/<fecha>-<sha>/   public/ src/ config/ db/migrations/ vendor/ frontend/dist/ .env -> ../../shared/.env
  current -> releases/...   enlace al release activo
  shared/.env               secretos, modo 600
  shared/backups/           respaldos de la base, los 7 más recientes
```

## 0. Antes de empezar

Corra la verificación de solo lectura y resuelva las fallas (no modifica nada):

```
ssh admin@host 'bash -s' < openspec/changes/despliegue-produccion-mariadb/verificar-servidor.sh
```

Decida el nombre de host (en estos ejemplos `flow.monoforms.com`). Cámbielo en un solo lugar: el `sed` del paso 4 y
`APP_ORIGIN` del paso 3.

## 1. Usuario de sistema y directorios

El usuario de la aplicación no tiene sudo ni shell interactiva, y no es el del blog (`www-data`).

```
sudo useradd --system --create-home --home-dir /home/flowbalance --shell /usr/sbin/nologin flowbalance
sudo install -d -o flowbalance -g flowbalance -m 755 /var/www/flowbalance /var/www/flowbalance/releases
sudo install -d -o flowbalance -g flowbalance -m 750 /var/www/flowbalance/shared
sudo install -d -o flowbalance -g flowbalance -m 700 /var/www/flowbalance/shared/backups
```

`shared` queda en 750: Nginx (`www-data`) llega a `current/frontend/dist` y a `current/public`, pero no a los secretos.

## 2. Base de datos y usuario

Un usuario con privilegios **solo** sobre `flow_balance` (nunca sobre `drupal`). Entre en el cliente y escriba la
contraseña allí, no en la línea de comandos, para que no quede en el historial del shell. Se crean dos usuarios porque
`localhost` y `127.0.0.1` son anfitriones distintos para MariaDB si el servidor resuelve nombres de forma estricta.

```
openssl rand -hex 24          # genere la contraseña y cópiela
sudo mariadb
```
```sql
CREATE DATABASE flow_balance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'flow_balance_app'@'localhost' IDENTIFIED BY '<contraseña>';
CREATE USER 'flow_balance_app'@'127.0.0.1' IDENTIFIED BY '<contraseña>';
GRANT ALL PRIVILEGES ON flow_balance.* TO 'flow_balance_app'@'localhost';
GRANT ALL PRIVILEGES ON flow_balance.* TO 'flow_balance_app'@'127.0.0.1';
```

El esquema no se crea aquí: lo migra el primer despliegue.

## 3. Secretos de la aplicación

Solo existen en el servidor; GitHub no los ve.

```
sudo install -m 600 -o flowbalance -g flowbalance deploy/env.production.example /var/www/flowbalance/shared/.env
sudo -u flowbalance nano /var/www/flowbalance/shared/.env     # APP_ORIGIN, APP_SECRET y DB_PASSWORD
```

`APP_SECRET`: `openssl rand -hex 32`. Con menos de 32 caracteres el inicio de sesión responde 503.

## 4. PHP-FPM y Nginx

Comparten el proceso maestro con el blog. Recargar es elegante (las peticiones en curso terminan), pero hágalo una
vez y fuera de horas pico.

```
sudo install -m 644 deploy/php-fpm/flowbalance.conf /etc/php/8.3/fpm/pool.d/flowbalance.conf
sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm

sudo install -m 644 deploy/nginx/flow-balance-security.conf /etc/nginx/snippets/flow-balance-security.conf
sed 's/@FLOW_HOST@/flow.monoforms.com/g' deploy/nginx/flow-balance.conf.template | sudo tee /etc/nginx/sites-available/flow-balance.conf >/dev/null
sudo ln -s /etc/nginx/sites-available/flow-balance.conf /etc/nginx/sites-enabled/flow-balance.conf
sudo nginx -t && sudo systemctl reload nginx
```

Antes del `nginx -t`, confirme que la llave del certificado es la que asume la plantilla:

```
grep -n ssl_certificate /etc/nginx/sites-enabled/monoforms.conf
```

Si `ssl_certificate_key` no es `/etc/nginx/ssl/cloudflare-origin.key`, corrija esa línea de la plantilla. El certificado
es el comodín de origen de Cloudflare (`*.monoforms.com`), vigente hasta 2041, así que no hace falta certbot ni
ningún token de la API de Cloudflare en el servidor.

El vhost **no** repite `set_real_ip_from` ni `real_ip_header`: los hereda de `monoforms.conf`, donde están a nivel
`http`. Si alguien los quita de ese archivo, el limitador de inicios de sesión empezaría a ver la IP de Cloudflare.

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

El respaldo es de la base de Flow Balance solamente. Se ensayó el ciclo completo: las 10 tablas, su contenido, el
esquema, las restricciones y el texto con emoji salieron idénticos.

```
# Respaldo. Con pipefail y comprobando el final: un respaldo vacío NO es un respaldo.
set -o pipefail
MYSQL_PWD='<contraseña>' mariadb-dump --single-transaction --quick --default-character-set=utf8mb4 \
  -u flow_balance_app flow_balance | gzip -9 > /var/www/flowbalance/shared/backups/flow_balance-$(date +%F-%H%M).sql.gz
zcat <archivo> | tail -1        # debe empezar por "-- Dump completed"

# Restauración en una base temporal, para comprobarla sin tocar la real.
sudo mariadb -e "CREATE DATABASE flow_balance_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
zcat <archivo> | sudo mariadb --default-character-set=utf8mb4 flow_balance_restore
sudo mariadb -e "CHECKSUM TABLE flow_balance.users, flow_balance_restore.users"      # y las demás tablas
sudo mariadb -e "DROP DATABASE flow_balance_restore"
```

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
| 502 en `/api/` | `systemctl status php8.3-fpm`; el socket `/run/php/flowbalance.sock` existe y es de `www-data:www-data` |
| 503 `configuration_unavailable` | `APP_ORIGIN` o `APP_SECRET` inválidos en `shared/.env`; `/var/log/php8.3-fpm.log` nombra el ajuste, nunca su valor |
| 503 `persistence_unavailable` | MariaDB activo, usuario y contraseña de `shared/.env`, `max_connections` |
| Código viejo tras un despliegue | Nginx debe usar `$realpath_root` (así viene en la plantilla); no hace falta recargar PHP-FPM |
| Errores de Nginx | `/var/log/nginx/flow-balance.error.log` |
| `WARNING: [pool flowbalance] server reached max_children (2)` en el registro de PHP-FPM | Hay peticiones en cola: es el límite deliberado de 2 procesos. Si es frecuente, revise qué las demora antes de subirlo; el servidor tiene 961 MB |

## 10. Acceso de administración (tarea 8.3)

Cree su usuario sin root con sudo y llave, entre con él **en una segunda sesión** y solo entonces desactive el acceso
de root y por contraseña (`PermitRootLogin no`, `PasswordAuthentication no` en `/etc/ssh/sshd_config.d/`). Si cierra la
sesión actual antes de probar, puede quedarse fuera. Este usuario no es `flowbalance`, que no lleva sudo.
