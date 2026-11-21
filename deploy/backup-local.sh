#!/usr/bin/env bash
# Respaldo MANUAL de la base de Flow Balance hacia su equipo (D29). Se ejecuta en su Mac, no en el servidor.
#
# El volcado viaja comprimido por la misma conexión SSH y se escribe directo en disco local: el servidor no guarda
# ninguna copia, así que no hay nada que borrar. El archivo se verifica antes de aceptarlo; si algo falla, se descarta.
#
#   FLOW_BACKUP_HOST=flowdeploy@host  deploy/backup-local.sh        respalda
#   deploy/backup-local.sh --status                                 dice qué tan viejo es el último respaldo
#   deploy/backup-local.sh --verify archivo.sql.gz                  solo verifica un archivo (lo usa restore-local.sh)
#
# Variables (todas opcionales salvo FLOW_BACKUP_HOST; puede ser un alias de ~/.ssh/config):
#   FLOW_BACKUP_DIR              destino, por defecto ~/Backups/flow-balance (fuera del repositorio)
#   FLOW_BACKUP_SSH_OPTS         opciones extra para ssh
#   FLOW_BACKUP_MIN_BYTES        tamaño mínimo aceptable, por defecto 2000
#   FLOW_BACKUP_EXPECTED_TABLES  mínimo de CREATE TABLE esperadas, por defecto 10
#   FLOW_BACKUP_STALE_DAYS       antigüedad que dispara el aviso, por defecto 7
set -uo pipefail

DIR="${FLOW_BACKUP_DIR:-$HOME/Backups/flow-balance}"
MIN_BYTES="${FLOW_BACKUP_MIN_BYTES:-2000}"
EXPECTED_TABLES="${FLOW_BACKUP_EXPECTED_TABLES:-10}"
STALE_DAYS="${FLOW_BACKUP_STALE_DAYS:-7}"
REMOTE_TOOL="/usr/local/bin/flowbalance-dump"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# Verifica un archivo de respaldo. Devuelve 0 si es aceptable; si no, explica por qué en stderr y devuelve 1.
verify_backup() {
  local file="$1" size last tables
  [ -f "$file" ] || { echo "no existe $file" >&2; return 1; }
  size=$(wc -c < "$file" | tr -d ' ')
  [ "$size" -ge "$MIN_BYTES" ] || { echo "tamaño sospechoso: $size bytes (mínimo $MIN_BYTES)" >&2; return 1; }
  gzip -t "$file" 2>/dev/null || { echo "la compresión está dañada o incompleta" >&2; return 1; }
  last=$(gzip -dc "$file" 2>/dev/null | tail -n 1)
  case "$last" in
    "-- Dump completed"*) ;;
    *) echo "el volcado no termina con '-- Dump completed' (incompleto)" >&2; return 1 ;;
  esac
  tables=$(gzip -dc "$file" 2>/dev/null | grep -c '^CREATE TABLE')
  [ "$tables" -ge "$EXPECTED_TABLES" ] || { echo "solo $tables tablas (se esperaban al menos $EXPECTED_TABLES)" >&2; return 1; }
  return 0
}

latest_backup() { ls -1t "$DIR"/flow_balance-*.sql.gz 2>/dev/null | head -n 1; }

report_age() {
  local latest
  latest=$(latest_backup)
  if [ -z "$latest" ]; then echo "Aún no hay respaldos locales en $DIR."; return 0; fi
  if [ -n "$(find "$latest" -mtime +"$STALE_DAYS" 2>/dev/null)" ]; then
    echo "AVISO: el último respaldo ($(basename "$latest")) tiene más de $STALE_DAYS días."
  else
    echo "Último respaldo: $(basename "$latest") (de los últimos $STALE_DAYS días)."
  fi
}

case "${1:-}" in
  --verify)
    [ -n "${2:-}" ] || die "uso: $0 --verify archivo.sql.gz"
    verify_backup "$2" && echo "OK: $2 es un respaldo completo." && exit 0
    exit 1 ;;
  --status) report_age; exit 0 ;;
  "") ;;
  *) die "opción desconocida: $1" ;;
esac

[ -n "${FLOW_BACKUP_HOST:-}" ] || die "defina FLOW_BACKUP_HOST (por ejemplo flowdeploy@host o un alias de ~/.ssh/config)"

mkdir -p "$DIR" || die "no se pudo crear $DIR"
chmod 700 "$DIR"
report_age

stamp=$(date +%Y-%m-%d-%H%M%S)
final="$DIR/flow_balance-$stamp.sql.gz"
partial="$DIR/.partial-$$"
trap 'rm -f "$partial"' EXIT
trap 'exit 130' INT TERM HUP

umask 077
# shellcheck disable=SC2086
if ! ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
      ${FLOW_BACKUP_SSH_OPTS:-} "$FLOW_BACKUP_HOST" "$REMOTE_TOOL" > "$partial"; then
  die "el volcado falló o la conexión se cortó; no se guardó nada"
fi

if ! verify_backup "$partial"; then
  die "el respaldo recibido no pasó la verificación; se descartó"
fi

mv "$partial" "$final" || die "no se pudo guardar $final"
chmod 600 "$final"
printf 'OK: %s (%s bytes)\n' "$final" "$(wc -c < "$final" | tr -d ' ')"
printf 'sha256: %s\n' "$(shasum -a 256 "$final" | cut -d' ' -f1)"
