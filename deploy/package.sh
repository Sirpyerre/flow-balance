#!/usr/bin/env bash
# Arma el paquete de despliegue desde una LISTA BLANCA (D19): solo lo indispensable para que Flow Balance corra.
# Lo que no está en la lista no se sube nunca, aunque se añada al repositorio mañana.
#
# Se ejecuta desde la raíz del repositorio DESPUÉS de construir:
#   composer install --no-dev --optimize-autoloader --no-interaction
#   (cd frontend && npm ci && APP_ORIGIN=https://<host> npm run build)
#   deploy/package.sh [directorio-de-salida]      # por defecto build/package
#
# Falla (y no deja paquete) si falta algo indispensable, si vendor/ trae dependencias de desarrollo o si aparece
# cualquier ruta prohibida.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/build/package}"
cd "$ROOT"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# Lo ÚNICO que viaja al servidor.
ALLOWED=(
  public
  src
  config
  db/migrations
  phinx.php
  composer.json
  composer.lock
  vendor
  frontend/dist
)

for item in "${ALLOWED[@]}"; do [ -e "$item" ] || die "falta '$item' (¿corrió composer install --no-dev y npm run build?)"; done
[ -f public/index.php ]            || die "falta public/index.php"
[ -f vendor/autoload.php ]         || die "falta vendor/autoload.php"
[ -f frontend/dist/index.html ]    || die "falta frontend/dist/index.html: el frontend no está construido"
grep -q '"dev": false' vendor/composer/installed.json 2>/dev/null \
  || die "vendor/ incluye dependencias de desarrollo: ejecute composer install --no-dev --optimize-autoloader"
[ ! -e vendor/bin/phpunit ] || die "vendor/ incluye PHPUnit: ejecute composer install --no-dev"

rm -rf "$OUT"
mkdir -p "$OUT"
for item in "${ALLOWED[@]}"; do
  mkdir -p "$OUT/$(dirname "$item")"
  # -a conserva permisos y enlaces; sin -X/-E no se copian atributos extendidos de macOS.
  rsync -a --exclude '.DS_Store' "$item" "$OUT/$(dirname "$item")/"
done

# Permisos uniformes (el servidor no depende del umask de quien construye): directorios 755 y archivos 644. Nginx
# (www-data) necesita leer frontend/dist; PHP ejecuta los scripts con `php`, no necesita el bit de ejecución.
find "$OUT" -type d -exec chmod 755 {} +
find "$OUT" -type f -exec chmod 644 {} +

# Inspección: ninguna ruta excluida puede estar presente. Se comprueba el paquete ya armado, no la lista.
violations=()
check_top() { [ -e "$OUT/$1" ] && violations+=("$1") || true; }
for top in docs specs openspec tests .specify .agents .claude .github .git .codex .cursor compose.yaml AGENTS.md \
           node_modules build client_body_temp fastcgi_temp proxy_temp nginx.pid; do check_top "$top"; done
for nested in db/seeders db/bootstrap frontend/src frontend/node_modules frontend/tests frontend/test-results \
              frontend/playwright-report frontend/playwright.config.ts frontend/package.json frontend/package-lock.json; do
  check_top "$nested"
done
while IFS= read -r found; do violations+=("${found#"$OUT"/}"); done < <(
  find "$OUT" \( -name '.env' -o -name '.env.*' -o -name '*.sql' -o -name '*.sql.gz' -o -name '.phpunit.*' \
                -o -name 'phpunit.xml*' -o -name '*.log' \) -not -path "$OUT/vendor/*" 2>/dev/null
)
# Dentro de vendor/ solo se buscan secretos de entorno reales; los README y pruebas de terceros no son un riesgo.
while IFS= read -r found; do violations+=("${found#"$OUT"/}"); done < <(find "$OUT/vendor" -name '.env' 2>/dev/null)

if [ "${#violations[@]}" -gt 0 ]; then
  printf 'ERROR: el paquete contiene rutas prohibidas:\n' >&2
  printf '  %s\n' "${violations[@]}" >&2
  rm -rf "$OUT"
  exit 1
fi

files=$(find "$OUT" -type f | wc -l | tr -d ' ')
size=$(du -sk "$OUT" | cut -f1)
printf 'Paquete listo en %s: %s archivos, %s MB\n' "$OUT" "$files" "$((size / 1024))"
printf 'Contenido de primer nivel: %s\n' "$(cd "$OUT" && ls -A | tr '\n' ' ')"
