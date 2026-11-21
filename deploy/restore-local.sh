#!/usr/bin/env bash
# Comprueba que un respaldo SE PUEDE restaurar: lo restaura en una base desechable de la MariaDB local (la del
# compose.yaml) y compara su contenido. La base desechable se elimina siempre al terminar. Un respaldo que nunca se
# restauró es una suposición.
#
#   deploy/restore-local.sh                          usa el último respaldo de ~/Backups/flow-balance
#   deploy/restore-local.sh archivo.sql.gz           usa ese archivo
#   deploy/restore-local.sh [archivo] --contra BASE  además compara tabla por tabla (CHECKSUM TABLE) con la base local BASE
#
# Sin --contra, que es el caso de un respaldo de producción (su base no es accesible desde aquí), exige que se
# restauren sin errores las tablas esperadas, con sus restricciones CHECK, y muestra filas y sumas por tabla.
#
# Credenciales: MARIADB_ROOT_PASSWORD de .env (solo local). Contenedor: servicio "mariadb" del compose.yaml.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="${FLOW_BACKUP_DIR:-$HOME/Backups/flow-balance}"
EXPECTED_TABLES="${FLOW_BACKUP_EXPECTED_TABLES:-10}"
SCRATCH="flow_balance_restore_$$"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

file="" ; against=""
while [ $# -gt 0 ]; do
  case "$1" in
    --contra) against="${2:-}"; [ -n "$against" ] || die "--contra necesita el nombre de una base"; shift 2 ;;
    -*) die "opción desconocida: $1" ;;
    *) file="$1"; shift ;;
  esac
done
[ -n "$file" ] || file=$(ls -1t "$DIR"/flow_balance-*.sql.gz 2>/dev/null | head -n 1)
[ -n "$file" ] && [ -f "$file" ] || die "no hay respaldo que restaurar (ni argumento ni archivos en $DIR)"

# Primero, la misma verificación que hace el respaldo: un archivo truncado se rechaza antes de tocar nada.
"$ROOT/deploy/backup-local.sh" --verify "$file" >/dev/null || die "el respaldo no pasa la verificación: $file"

pw=$(grep -E '^MARIADB_ROOT_PASSWORD=' "$ROOT/.env" 2>/dev/null | tail -n 1 | cut -d= -f2-)
[ -n "$pw" ] || die "falta MARIADB_ROOT_PASSWORD en .env"
container=$(cd "$ROOT" && docker compose ps -q mariadb 2>/dev/null | head -n 1)
[ -n "$container" ] || die "el servicio mariadb del compose.yaml no está en ejecución (docker compose up -d mariadb)"

# La contraseña pasa por el entorno del proceso docker, no por argumentos.
export MYSQL_PWD="$pw"
sql() { docker exec -i -e MYSQL_PWD "$container" mariadb -uroot --batch --skip-column-names "$@"; }

cleanup() { sql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`" >/dev/null 2>&1; }
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

sql -e "CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || die "no se pudo crear la base desechable"
if ! gzip -dc "$file" | sql --default-character-set=utf8mb4 "$SCRATCH"; then
  die "la restauración terminó con errores"
fi

tables=$(sql -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SCRATCH' AND TABLE_TYPE='BASE TABLE'")
[ "$tables" -ge "$EXPECTED_TABLES" ] || die "se restauraron $tables tablas (se esperaban al menos $EXPECTED_TABLES)"
in_dump=$(gzip -dc "$file" | grep -c '^CREATE TABLE')
[ "$tables" -eq "$in_dump" ] || die "el respaldo define $in_dump tablas pero se restauraron $tables"
checks=$(sql -e "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$SCRATCH'")
[ "$checks" -gt 0 ] || die "no se restauró ninguna restricción CHECK"
# Las restricciones están en las definiciones de columna (CHECK inline) y en CONSTRAINT ... CHECK; las líneas de datos
# empiezan por INSERT y se excluyen para que un dato que diga "CHECK (" no altere la cuenta.
dump_checks=$(gzip -dc "$file" | grep -v '^INSERT' | grep -o 'CHECK (' | wc -l | tr -d ' ')
[ "$checks" -eq "$dump_checks" ] || die "el respaldo define $dump_checks restricciones CHECK y se restauraron $checks"

printf 'Restaurado %s en %s: %s tablas, %s restricciones CHECK\n' "$(basename "$file")" "$SCRATCH" "$tables" "$checks"
printf '%-34s %10s\n' TABLA FILAS
for t in $(sql -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SCRATCH' AND TABLE_TYPE='BASE TABLE' ORDER BY 1"); do
  printf '%-34s %10s\n' "$t" "$(sql -e "SELECT COUNT(*) FROM \`$SCRATCH\`.\`$t\`")"
done

if [ -n "$against" ]; then
  exists=$(sql -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$against'")
  [ "$exists" -eq 1 ] || die "la base de comparación '$against' no existe"
  bad=0
  for t in $(sql -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SCRATCH' AND TABLE_TYPE='BASE TABLE' ORDER BY 1"); do
    a=$(sql -e "CHECKSUM TABLE \`$SCRATCH\`.\`$t\`" | cut -f2)
    b=$(sql -e "CHECKSUM TABLE \`$against\`.\`$t\`" 2>/dev/null | cut -f2)
    if [ "$a" != "$b" ]; then printf 'DIFERENTE: %s (restaurada %s, origen %s)\n' "$t" "$a" "${b:-NULL}"; bad=1; fi
  done
  other=$(sql -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$against' AND TABLE_TYPE='BASE TABLE'")
  [ "$other" -eq "$tables" ] || { printf 'DIFERENTE: la base %s tiene %s tablas y la restaurada %s\n' "$against" "$other" "$tables"; bad=1; }
  [ "$bad" -eq 0 ] || die "la base restaurada NO coincide con $against"
  echo "OK: las $tables tablas coinciden con $against (CHECKSUM TABLE)."
else
  echo "OK: el respaldo se restaura sin errores y con el esquema esperado."
fi
