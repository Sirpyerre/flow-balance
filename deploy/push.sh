#!/usr/bin/env bash
# Sube un paquete al servidor y activa el release. Es lo único que hace el pipeline contra el VPS, y por eso vive en
# un script que también se puede ensayar a mano.
#
#   DEPLOY_HOST=<ip-o-dns> DEPLOY_USER=flowdeploy DEPLOY_SSH_KEY_FILE=<llave-privada> \
#   DEPLOY_KNOWN_HOSTS="$(cat known_hosts)" deploy/push.sh <directorio-del-paquete> <release> <host-público>
#
# Credenciales: la llave privada se lee de un archivo (el pipeline la escribe desde el secreto y la borra al terminar);
# la huella del servidor llega fijada en DEPLOY_KNOWN_HOSTS y la verificación NUNCA se desactiva. Antes de transferir
# nada se hace una conexión de prueba: con una huella distinta el script aborta sin subir un solo archivo.
set -Eeuo pipefail

PKG="${1:?uso: push.sh <directorio-del-paquete> <release> <host-público>}"
RELEASE="${2:?uso: push.sh <directorio-del-paquete> <release> <host-público>}"
APP_HOST="${3:?uso: push.sh <directorio-del-paquete> <release> <host-público>}"

: "${DEPLOY_HOST:?falta DEPLOY_HOST}" "${DEPLOY_USER:?falta DEPLOY_USER}"
: "${DEPLOY_KNOWN_HOSTS:?falta DEPLOY_KNOWN_HOSTS (la huella fijada del servidor)}"
: "${DEPLOY_SSH_KEY_FILE:?falta DEPLOY_SSH_KEY_FILE}"
PORT="${DEPLOY_PORT:-22}"
APP_DIR="${DEPLOY_APP_DIR:-/var/www/flowbalance}"
HERE="$(cd "$(dirname "$0")" && pwd)"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[ -d "$PKG" ] && [ -f "$PKG/public/index.php" ] || die "'$PKG' no es un paquete de Flow Balance (falta public/index.php)"
[ -f "$DEPLOY_SSH_KEY_FILE" ] || die "no existe la llave privada $DEPLOY_SSH_KEY_FILE"
[[ "$RELEASE" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$ ]] || die "nombre de release inválido"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "$work/known_hosts"

SSH=(ssh -i "$DEPLOY_SSH_KEY_FILE" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes
         -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$work/known_hosts" -o ServerAliveInterval=15)
TARGET="$DEPLOY_USER@$DEPLOY_HOST"

printf '==> Verificando la huella del servidor\n'
"${SSH[@]}" "$TARGET" true || die "no se pudo verificar el servidor (huella distinta, llave rechazada o sin conexión); no se transfirió nada"

printf '==> Subiendo el paquete a releases/%s\n' "$RELEASE"
printf -v ssh_cmd '%q ' "${SSH[@]}"
rsync -a -e "$ssh_cmd" "$PKG"/ "$TARGET:$APP_DIR/releases/$RELEASE/"

printf '==> Activando el release en el servidor\n'
"${SSH[@]}" "$TARGET" "bash -s -- '$RELEASE' '$APP_HOST'" < "$HERE/release.sh"
