#!/usr/bin/env bash
# Verificación de SOLO LECTURA de los requisitos del VPS para Flow Balance.
# No escribe archivos, no cambia configuración, no reinicia servicios, no crea nada
# y no imprime contraseñas ni el contenido de archivos de entorno.
#
# Uso (desde su máquina):
#   ! ssh usuario@host 'bash -s' < openspec/changes/despliegue-produccion-mariadb/verificar-servidor.sh
#
# Los puntos que requieren privilegios usan `sudo -n` (sin pedir contraseña). Si no hay permiso,
# se marcan como OMITIDO y se indica qué ejecutar con un usuario que sí lo tenga.

set -u
export LC_ALL=C

PHP_MIN="8.3"
PHP_FPM_SVC="php8.3-fpm"
APP_NAME="flowbalance"
APP_DIR="/var/www/${APP_NAME}"
APP_DB="flow_balance"
APP_DB_USER="flow_balance_app"
MIN_FREE_RAM_MB=150
MIN_FREE_DISK_GB=3

N_OK=0; N_WARN=0; N_FAIL=0; N_PEND=0; N_SKIP=0

ok()   { printf '  [OK]        %s\n' "$*"; N_OK=$((N_OK + 1)); }
warn() { printf '  [ADVERT.]   %s\n' "$*"; N_WARN=$((N_WARN + 1)); }
fail() { printf '  [FALLA]     %s\n' "$*"; N_FAIL=$((N_FAIL + 1)); }
pend() { printf '  [PENDIENTE] %s\n' "$*"; N_PEND=$((N_PEND + 1)); }
skip() { printf '  [OMITIDO]   %s\n' "$*"; N_SKIP=$((N_SKIP + 1)); }
head1() { printf '\n== %s ==\n' "$*"; }

have() { command -v "$1" >/dev/null 2>&1; }
# Ejecuta con privilegios solo si es posible sin contraseña; si no, devuelve 1 sin ruido.
priv() {
  if [ "$(id -u)" -eq 0 ]; then "$@"; return $?; fi
  have sudo && sudo -n "$@" 2>/dev/null
}
version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }

printf 'Verificación de solo lectura para Flow Balance\n'
printf 'Host: %s | Usuario: %s | Fecha: %s\n' "$(hostname)" "$(id -un)" "$(date -u +%FT%TZ)"

# ---------------------------------------------------------------- Sistema
head1 "Sistema"
if [ -r /etc/os-release ]; then
  . /etc/os-release
  case "${VERSION_ID:-}" in
    24.04) ok "Sistema operativo: ${PRETTY_NAME}" ;;
    *)     warn "Sistema operativo distinto de Ubuntu 24.04: ${PRETTY_NAME:-desconocido}" ;;
  esac
else
  warn "No se pudo leer /etc/os-release"
fi

cpus=$(nproc 2>/dev/null || echo 1)
ok "CPU: ${cpus} vCPU, carga 1/5/15 min: $(cut -d' ' -f1-3 /proc/loadavg)"

mem_total=$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)
mem_avail=$(awk '/^MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
swap_total=$(awk '/^SwapTotal:/ {print int($2/1024)}' /proc/meminfo)
swap_free=$(awk '/^SwapFree:/ {print int($2/1024)}' /proc/meminfo)
swap_used=$((swap_total - swap_free))
printf '  RAM total %s MB | disponible %s MB | swap usada %s de %s MB\n' \
  "$mem_total" "$mem_avail" "$swap_used" "$swap_total"
if [ "$mem_avail" -lt "$MIN_FREE_RAM_MB" ]; then
  fail "RAM disponible (${mem_avail} MB) por debajo de ${MIN_FREE_RAM_MB} MB"
elif [ "$swap_total" -gt 0 ] && [ "$swap_used" -gt $((swap_total / 2)) ]; then
  warn "Más de la mitad del swap en uso (${swap_used} MB): el servidor ya está presionado"
else
  ok "Memoria suficiente para un pool adicional de 2 procesos"
fi
[ "$swap_total" -gt 0 ] && ok "Swap configurado" || warn "Sin swap: un pico de memoria podría matar procesos"

disk_free=$(df -BG --output=avail / | tail -n1 | tr -dc '0-9')
disk_pct=$(df --output=pcent / | tail -n1 | tr -dc '0-9')
if [ "${disk_free:-0}" -lt "$MIN_FREE_DISK_GB" ]; then
  fail "Espacio libre en / (${disk_free} GB) por debajo de ${MIN_FREE_DISK_GB} GB"
else
  ok "Disco: ${disk_free} GB libres (${disk_pct}% usado)"
fi

# ---------------------------------------------------------------- PHP
head1 "PHP"
if have php; then
  php_cli=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;')
  if version_ge "$php_cli" "$PHP_MIN"; then ok "PHP CLI ${php_cli} (mínimo ${PHP_MIN})"; else fail "PHP CLI ${php_cli} < ${PHP_MIN}"; fi
else
  fail "php (CLI) no está instalado: Phinx necesita PHP en la línea de comandos"
fi

fpm_bin=$(command -v php-fpm8.3 || command -v php-fpm || true)
if [ -n "$fpm_bin" ]; then
  ok "Binario FPM: $("$fpm_bin" -v 2>/dev/null | head -n1 | cut -d' ' -f1-2)"
else
  fail "No se encontró php-fpm"
fi

if have systemctl; then
  if systemctl is-active --quiet "$PHP_FPM_SVC"; then ok "Servicio ${PHP_FPM_SVC} activo"; else fail "Servicio ${PHP_FPM_SVC} NO está activo"; fi
fi

if have php; then
  mods=$(php -m 2>/dev/null | tr 'A-Z' 'a-z')
  for ext in pdo_mysql mbstring intl bcmath ctype json; do
    if printf '%s\n' "$mods" | grep -qx "$ext"; then ok "Extensión CLI: ${ext}"; else fail "Falta la extensión CLI: ${ext}"; fi
  done
  fpm_ini="/etc/php/8.3/fpm"
  if [ -d "$fpm_ini/conf.d" ]; then
    for ext in pdo_mysql mbstring intl bcmath; do
      if ls "$fpm_ini"/conf.d/ 2>/dev/null | grep -qi "${ext}"; then ok "Extensión FPM habilitada: ${ext}"; else warn "No se confirmó ${ext} en ${fpm_ini}/conf.d (puede estar compilada)"; fi
    done
  fi

  dis_cli=$(php -r 'echo ini_get("disable_functions");')
  if printf '%s' "$dis_cli" | grep -q "proc_open"; then
    warn "disable_functions de CLI bloquea proc_open: Composer no puede correr aquí (el diseño ya construye fuera del servidor)"
  else
    ok "disable_functions de CLI no bloquea proc_open"
  fi
  for fn in putenv exec; do
    printf '%s' "$dis_cli" | grep -q "$fn" && printf '  nota: CLI bloquea %s\n' "$fn"
  done

  tz=$(php -r 'echo ini_get("date.timezone");')
  if [ -z "$tz" ]; then
    warn "date.timezone vacío en CLI (el código usa UTC explícito; se informa por si acaso)"
  else
    ok "date.timezone CLI: ${tz}"
  fi
fi

if [ -r "${fpm_ini:-/nonexistent}/php.ini" ]; then
  printf '  php.ini FPM:'
  for k in memory_limit max_execution_time post_max_size; do
    v=$(grep -E "^[[:space:]]*${k}[[:space:]]*=" "$fpm_ini/php.ini" | tail -n1 | cut -d= -f2 | tr -d ' ')
    printf ' %s=%s' "$k" "${v:-?}"
  done
  printf '\n'
  grep -Eq '^[[:space:]]*opcache.validate_timestamps[[:space:]]*=[[:space:]]*1' "$fpm_ini/php.ini" 2>/dev/null \
    && ok "OPcache revalida marcas de tiempo (el diseño usa \$realpath_root, no depende de esto)"
fi

# ---------------------------------------------------------------- Pools FPM
head1 "Pools PHP-FPM"
pools_dir="/etc/php/8.3/fpm/pool.d"
if [ -d "$pools_dir" ]; then
  total_children=0
  for f in "$pools_dir"/*.conf; do
    [ -e "$f" ] || continue
    mc=$(grep -E '^[[:space:]]*pm.max_children' "$f" | head -n1 | cut -d= -f2 | tr -d ' ')
    pmode=$(grep -E '^[[:space:]]*pm[[:space:]]*=' "$f" | head -n1 | cut -d= -f2 | tr -d ' ')
    printf '  %s: pm=%s max_children=%s\n' "$(basename "$f")" "${pmode:-?}" "${mc:-?}"
    total_children=$((total_children + ${mc:-0}))
  done
  if [ -e "$pools_dir/${APP_NAME}.conf" ]; then ok "Pool dedicado ${APP_NAME} ya existe"; else pend "Pool dedicado ${APP_NAME} (se crea con deploy/php-fpm/flowbalance.conf)"; fi
  printf '  Suma de max_children existentes: %s\n' "$total_children"
else
  warn "No se pudo leer ${pools_dir}"
fi

# ---------------------------------------------------------------- Nginx
head1 "Nginx"
if have nginx; then
  ok "Versión: $(nginx -v 2>&1 | sed 's/^nginx version: //')"
  nv=$(nginx -V 2>&1)
  printf '%s' "$nv" | grep -q "http_ssl_module" && ok "Módulo http_ssl" || fail "Falta http_ssl_module"
  printf '%s' "$nv" | grep -q "http_v2_module"  && ok "Módulo http_v2" || warn "Sin http_v2"
  printf '%s' "$nv" | grep -q "http_v3_module"  && ok "HTTP/3 compilado" || printf '  nota: HTTP/3 no compilado (el diseño no lo usa)\n'
  printf '%s' "$nv" | grep -qi "brotli"         && ok "Brotli compilado" || printf '  nota: Brotli no compilado (el diseño usa solo gzip)\n'
else
  fail "nginx no está instalado o no está en PATH"
fi

if have systemctl; then
  systemctl is-active --quiet nginx && ok "Servicio nginx activo" || fail "Servicio nginx NO está activo"
fi

if have nginx; then
  if out=$(priv nginx -t 2>&1); then
    ok "nginx -t: la configuración actual es válida"
  elif [ -z "$out" ]; then
    skip "nginx -t requiere privilegios (sudo sin contraseña no disponible). Ejecute: sudo nginx -t"
  else
    fail "nginx -t reporta errores"
  fi

  if dump=$(priv nginx -T 2>/dev/null) && [ -n "$dump" ]; then
    # Solo cuenta y ubicación; no se imprime el contenido de la configuración.
    http_cache=$(printf '%s\n' "$dump" | grep -cE '^[[:space:]]*fastcgi_cache[[:space:]]+[a-z_]+;')
    printf '  Directivas "fastcgi_cache <zona>;" en toda la configuración: %s\n' "$http_cache"
    printf '%s\n' "$dump" | grep -E '^[[:space:]]*fastcgi_cache_path' | sed 's/^[[:space:]]*/  /' | cut -c1-110
    printf '%s\n' "$dump" | grep -qiE 'server_name[^;]*flow' && warn "Ya hay un server_name con 'flow': revisar que no choque" || pend "vhost de Flow Balance (se crea con deploy/nginx/flow-balance.conf)"
    warn "Revisar manualmente que 'fastcgi_cache' NO esté a nivel http{} (heredaría al vhost de Flow)"
  else
    skip "nginx -T requiere privilegios. Ejecute: sudo nginx -T | grep -n fastcgi_cache"
  fi
fi

for port in 80 443; do
  if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${port}$"; then ok "Puerto ${port} en escucha"; else warn "Puerto ${port} sin escucha"; fi
done
have certbot && ok "certbot instalado" || pend "certbot no encontrado (necesario para el certificado del subdominio)"

# ---------------------------------------------------------------- MariaDB
head1 "MariaDB"
mbin=$(command -v mariadb || command -v mysql || true)
if [ -n "$mbin" ]; then
  ok "Cliente: $("$mbin" --version 2>/dev/null | cut -c1-90)"
else
  fail "No se encontró el cliente mariadb/mysql"
fi
if have systemctl; then
  systemctl is-active --quiet mariadb && ok "Servicio mariadb activo" || fail "Servicio mariadb NO está activo"
fi

listen=$(ss -ltn 2>/dev/null | awk '$4 ~ /:3306$/ {print $4}')
case "$listen" in
  127.0.0.1:3306|"[::1]:3306"|"127.0.0.1:3306
[::1]:3306") ok "MariaDB escucha solo en loopback (${listen})" ;;
  "") warn "No se detectó escucha en 3306 (¿socket unix únicamente?)" ;;
  *) fail "MariaDB escucha fuera de loopback: ${listen}" ;;
esac

if [ -n "$mbin" ] && priv "$mbin" -NBe 'SELECT 1' >/dev/null; then
  q() { priv "$mbin" -NBe "$1" 2>/dev/null; }
  ver=$(q 'SELECT VERSION()')
  case "$ver" in
    10.11*|10.1[2-9]*|11.*) ok "Versión del servidor: ${ver}" ;;
    *) warn "Versión inesperada: ${ver} (el diseño apunta a MariaDB 10.11)" ;;
  esac
  maxc=$(q "SELECT @@max_connections"); used=$(q "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME='Max_used_connections'")
  printf '  max_connections=%s | máximo usado desde el arranque=%s\n' "$maxc" "$used"
  [ "${used:-0}" -ge $((maxc - 4)) ] && warn "Conexiones al límite: dos más de Flow pueden comprimir al blog" || ok "Margen de conexiones para 2 de Flow Balance"
  printf '  charset=%s collation=%s innodb_buffer_pool=%s MB\n' "$(q 'SELECT @@character_set_server')" "$(q 'SELECT @@collation_server')" "$(q 'SELECT ROUND(@@innodb_buffer_pool_size/1048576)')"
  printf '  sql_mode: %s\n' "$(q 'SELECT @@GLOBAL.sql_mode')"
  printf '  log_bin=%s log_bin_trust_function_creators=%s\n' "$(q 'SELECT @@log_bin')" "$(q 'SELECT @@log_bin_trust_function_creators')"
  if [ "$(q "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${APP_DB}'")" = "1" ]; then ok "Base ${APP_DB} ya existe"; else pend "Base ${APP_DB} (se crea con el aprovisionamiento manual)"; fi
  if [ "$(q "SELECT COUNT(*) FROM mysql.user WHERE User='${APP_DB_USER}'")" -ge 1 ] 2>/dev/null; then ok "Usuario ${APP_DB_USER} ya existe"; else pend "Usuario ${APP_DB_USER}@localhost"; fi
else
  skip "Consultas a MariaDB requieren acceso administrador. Ejecute con un usuario con sudo: sudo mariadb -e \"SELECT VERSION(), @@max_connections;\""
fi

# ---------------------------------------------------------------- Aplicación
head1 "Recursos de Flow Balance"
id "$APP_NAME" >/dev/null 2>&1 && ok "Usuario de sistema ${APP_NAME} existe" || pend "Usuario de sistema ${APP_NAME}"
if [ -d "$APP_DIR" ]; then
  ok "${APP_DIR} existe"
  for d in releases shared; do [ -d "$APP_DIR/$d" ] && ok "${APP_DIR}/${d}" || pend "${APP_DIR}/${d}"; done
  if [ -e "$APP_DIR/shared/.env" ]; then
    perm=$(stat -c '%a %U' "$APP_DIR/shared/.env" 2>/dev/null)
    case "$perm" in
      "600 ${APP_NAME}") ok "shared/.env con modo 600 y dueño ${APP_NAME}" ;;
      *) fail "shared/.env con permisos '${perm}' (debe ser 600 ${APP_NAME})" ;;
    esac
  else
    pend "shared/.env (se crea a mano; nunca se sube desde GitHub)"
  fi
else
  pend "Directorio ${APP_DIR}"
fi
have rsync && ok "rsync disponible (lo usa el pipeline)" || fail "rsync no instalado (el pipeline lo necesita en el servidor)"
have gzip && ok "gzip disponible" || warn "gzip no encontrado (respaldos)"
have mariadb-dump || have mysqldump && ok "mariadb-dump/mysqldump disponible (respaldos)" || fail "Falta mariadb-dump o mysqldump"

head1 "Seguridad básica"
if have ufw; then
  st=$(priv ufw status 2>/dev/null | head -n1); [ -n "$st" ] && printf '  ufw: %s\n' "$st" || skip "ufw requiere privilegios"
fi
ss -ltn 2>/dev/null | awk 'NR>1 {print $4}' | grep -vE '^(127\.|\[::1\]|::1)' | sed 's/^/  escucha pública: /' | sort -u
printf '  nota: revise la lista anterior; solo deben aparecer 22, 80 y 443\n'

# ---------------------------------------------------------------- Resumen
head1 "Resumen"
printf '  OK: %s | Advertencias: %s | Fallas: %s | Pendientes: %s | Omitidos: %s\n' "$N_OK" "$N_WARN" "$N_FAIL" "$N_PEND" "$N_SKIP"
if [ "$N_FAIL" -gt 0 ]; then
  printf '  Resultado: NO CUMPLE (hay fallas que resolver antes de desplegar)\n'
  exit 1
fi
printf '  Resultado: CUMPLE los requisitos comprobados (los pendientes se crean en el aprovisionamiento)\n'
exit 0
