#!/usr/bin/env bash
# Activa un release de Flow Balance. Se ejecuta EN EL SERVIDOR, sin root, como flowdeploy, y viaja por stdin desde
# el pipeline (no se instala en el servidor, así que siempre es la versión del commit que se despliega):
#
#   ssh flowdeploy@host 'bash -s -- <release> <host>' < deploy/release.sh
#
# El paquete ya debe estar en /var/www/flowbalance/releases/<release>/ (lo sube rsync). Este script, en orden:
#   1. enlaza shared/.env dentro del release
#   2. respalda la base de Flow Balance (solo esa) y VERIFICA el respaldo antes de seguir (D27)
#   3. migra; si falla, no activa nada
#   4. activa el release cambiando el enlace "current" en una sola operación (mv -T)
#   5. consulta /api/health desde el propio servidor, sin pasar por Cloudflare; si falla, devuelve el enlace
#   6. conserva el activo y los 3 releases más recientes, y los 7 respaldos más recientes
#
# Códigos de salida: 10 precondición, 20 respaldo, 30 migración, 40 salud fallida con reversión correcta,
# 41 salud fallida y la reversión también falló, 50 otro despliegue en curso.
set -Eeuo pipefail
umask 027

RELEASE="${1:?uso: release.sh <release> <host>}"
HOST="${2:?uso: release.sh <release> <host>}"

APP_DIR="${FLOW_APP_DIR:-/var/www/flowbalance}"
DUMP_TOOL="${FLOW_DUMP_TOOL:-/usr/local/bin/flowbalance-dump}"
KEEP_RELEASES=3
KEEP_BACKUPS=7
HEALTH_TRIES="${FLOW_HEALTH_TRIES:-10}"
HEALTH_WAIT="${FLOW_HEALTH_WAIT:-2}"

say() { printf '==> %s\n' "$*"; }
die() { local code="$1"; shift; printf 'ERROR: %s\n' "$*" >&2; exit "$code"; }

# Los argumentos llegan del pipeline, pero se validan igual: terminan en rutas y en un nombre de host.
[[ "$RELEASE" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$ ]] || die 10 "nombre de release inválido"
[[ "$HOST" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die 10 "host inválido"

REL_DIR="$APP_DIR/releases/$RELEASE"
BK_DIR="$APP_DIR/shared/backups"
CURRENT="$APP_DIR/current"

[ -d "$REL_DIR" ]                || die 10 "no existe $REL_DIR (¿se subió el paquete?)"
[ -f "$REL_DIR/public/index.php" ] || die 10 "el release no trae public/index.php"
[ -f "$REL_DIR/vendor/autoload.php" ] || die 10 "el release no trae vendor/autoload.php"
[ -f "$APP_DIR/shared/.env" ]    || die 10 "falta $APP_DIR/shared/.env (lo crea el playbook de aprovisionamiento)"
[ -x "$DUMP_TOOL" ]              || die 10 "falta $DUMP_TOOL (lo instala el playbook de aprovisionamiento)"
command -v php  >/dev/null       || die 10 "no hay php en el servidor"
command -v curl >/dev/null       || die 10 "no hay curl en el servidor"

# Un solo despliegue a la vez.
exec 9>"$APP_DIR/.deploy.lock"
flock -n 9 || die 50 "hay otro despliegue en curso"

previous=""
[ -L "$CURRENT" ] && previous="$(readlink "$CURRENT")"

point_current_to() {   # point_current_to releases/<id>: cambio atómico del enlace
  local tmp="$APP_DIR/.current.$$"
  ln -sfn "$1" "$tmp"
  mv -T "$tmp" "$CURRENT"
}

health() {
  # -k: el certificado de origen de Cloudflare no es de una CA pública. --resolve: va a este mismo servidor.
  curl -fsS --max-time 10 -k --resolve "$HOST:443:127.0.0.1" "https://$HOST/api/health" 2>/dev/null | grep -q '"status":"ok"'
}

say "Release $RELEASE (activo hoy: ${previous:-ninguno})"

# 1. Entorno compartido
ln -sfn ../../shared/.env "$REL_DIR/.env"

# 2. Respaldo previo a migrar. Un respaldo vacío no falla, es un falso verde: se comprueba que termine completo.
say "Respaldo de la base de Flow Balance"
mkdir -p "$BK_DIR"; chmod 700 "$BK_DIR"
backup="$BK_DIR/flow_balance-pre-$RELEASE.sql.gz"
partial="$backup.partial"
if ! "$DUMP_TOOL" > "$partial"; then
  rm -f "$partial"; die 20 "el respaldo falló; no se migró nada y el release no se activó"
fi
if ! gzip -t "$partial" 2>/dev/null || [ "$(gzip -dc "$partial" | tail -n 1 | cut -c1-17)" != "-- Dump completed" ]; then
  rm -f "$partial"; die 20 "el respaldo quedó vacío o incompleto (no termina con '-- Dump completed'); no se migró nada y el release no se activó"
fi
mv "$partial" "$backup"
say "Respaldo verificado: $backup ($(wc -c < "$backup" | tr -d ' ') bytes)"

# 3. Migraciones. Si fallan, "current" no se toca; el respaldo queda para restaurar si hiciera falta.
say "Migraciones"
if ! (cd "$REL_DIR" && php vendor/bin/phinx migrate --no-interaction); then
  die 30 "la migración falló; el release anterior sigue activo (${previous:-ninguno}) y el respaldo está en $backup"
fi

# 4. Activación atómica. OPcache y la caché de rutas de PHP ven archivos nuevos porque Nginx usa $realpath_root.
say "Activando"
point_current_to "releases/$RELEASE"

# 5. Salud, desde el propio servidor.
say "Comprobando /api/health en $HOST (sin pasar por Cloudflare)"
healthy=0
for _ in $(seq 1 "$HEALTH_TRIES"); do
  if health; then healthy=1; break; fi
  sleep "$HEALTH_WAIT"
done
if [ "$healthy" -ne 1 ]; then
  printf 'ERROR: /api/health no respondió bien tras activar %s\n' "$RELEASE" >&2
  if [ -n "$previous" ] && [ -d "$APP_DIR/$previous" ]; then
    point_current_to "$previous"
    if health; then
      die 40 "se devolvió el enlace al release anterior ($previous), que responde bien. Las migraciones del release fallido NO se revierten: el respaldo previo está en $backup"
    fi
    die 41 "se devolvió el enlace a $previous pero /api/health tampoco responde: revise el servidor. Respaldo previo: $backup"
  fi
  rm -f "$CURRENT"
  die 41 "no había un release anterior al que volver; se retiró el enlace 'current'. Respaldo previo: $backup"
fi
say "Salud correcta"

# 6. Limpieza: se conserva el activo y los $KEEP_RELEASES releases más recientes; nunca se borra el activo.
active="$(readlink "$CURRENT")"; active="${active##*/}"
mapfile -t all < <(find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)
keep=("$active")
total=${#all[@]}
for ((i = total > KEEP_RELEASES ? total - KEEP_RELEASES : 0; i < total; i++)); do keep+=("${all[$i]}"); done
for name in "${all[@]}"; do
  [[ "$name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || continue
  skip=0; for k in "${keep[@]}"; do [ "$name" = "$k" ] && skip=1; done
  [ "$skip" -eq 1 ] || { say "Eliminando release antiguo $name"; rm -rf -- "${APP_DIR:?}/releases/$name"; }
done
mapfile -t old_backups < <(find "$BK_DIR" -maxdepth 1 -type f -name 'flow_balance-pre-*.sql.gz' -printf '%f\n' | sort | head -n "-$KEEP_BACKUPS" 2>/dev/null || true)
for name in "${old_backups[@]}"; do [ -n "$name" ] && rm -f -- "${BK_DIR:?}/$name"; done

printf 'RELEASE_OK %s anterior=%s\n' "$RELEASE" "${previous:-ninguno}"
