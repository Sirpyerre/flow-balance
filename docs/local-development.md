# Desarrollo local

Configura `.env` siguiendo [la guía de variables](configuration.md). Ejecuta
`composer install`, `docker compose up -d --build --wait` y `composer db:migrate`
desde la raíz. Para pruebas, utiliza `APP_ENV=testing` con los ajustes `TEST_DB_*`
y aplica migraciones antes de ejecutar `composer test`.

Usa Herd para servir `public/` en `flow-balance-api.test`. Desde `frontend/` ejecuta
`npm ci` y `npm run dev`. Configura el proxy HTTPS de Herd para que
`https://flow-balance.test` apunte a Astro en `127.0.0.1:4321`. Astro reenvía
`/api` al sitio PHP; el navegador usa únicamente el origen HTTPS público.

Consulta el [contrato de enrutamiento](../specs/001-account-access/contracts/development-routing.md)
para las cabeceras y la configuración de Herd. El limitador usa la IP de conexión por defecto. La configuración de IP efectiva para Herd y Astro se describe más abajo.

La ruta `/` abre la landing y sus accesos a registro e inicio de sesión.
Consulta la [especificación visual](desing/desing-frontend.md) para revisar la
implementación y los estados del formulario.

## Cuenta de prueba

Con PostgreSQL activo y las migraciones aplicadas, ejecuta `composer db:seed`.
El seeder acepta únicamente la base local de desarrollo de Flow Balance y se
puede volver a ejecutar sin duplicar la cuenta ni cambiar su contraseña.

- Correo: `demo@flowbalance.test`
- Contraseña: `FlowBalance-Demo-2026!`

La cuenta empieza con el tablero vacío. Úsala en el entorno local.

## Instalar y probar la PWA

Para conectar un Android por cable, consulta la [guía de prueba por USB](android-usb.md).

Abre `https://flow-balance.test/` desde el teléfono con un origen HTTPS de confianza
y accesible para ese dispositivo. En Chrome usa la opción de instalar del menú
del navegador. En Safari en iOS usa **Compartir → Agregar a pantalla de inicio**.
Los dominios locales de Herd se resuelven en la Mac. Para un teléfono físico usa
un entorno de prueba accesible desde la red y con un certificado de confianza.

Para validar el resultado compilado, ejecuta `npm --prefix frontend run build`,
detén Astro Dev y ejecuta `npm --prefix frontend run preview -- --host 127.0.0.1 --port 4321`
detrás del proxy HTTPS de Herd. El servidor debe entregar `/service-worker.js`
como JavaScript y enviar `Cache-Control: no-cache` para ese recurso. En un
despliegue, configura el servidor para dirigir `/api` a Mezzio en el mismo origen
y enviar `Cache-Control: private, no-store` para los documentos privados.

El service worker guarda únicamente recursos públicos permitidos. Al abrir una
página sin conexión muestra un aviso. Los datos de cuenta requieren conexión
y una sesión vigente.

## Registro de ingresos

Aplica las migraciones con `herd composer db:migrate` y abre el tablero en
`https://flow-balance.test/dashboard`. Desde **Proyectos** puedes dar de alta
un cliente y su proyecto, registrar un cobro en MXN y abrir su detalle para
capturar cargos separados. El selector de mes del tablero enlaza cada cobro
que integra el importe abonado del periodo.

El tablero, `/clients/new`, `/projects/new`, `/projects/view`, `/payments/new`
y `/payments/view` son rutas privadas. Sus documentos y las respuestas privadas
de `/api` se sirven con `Cache-Control: private, no-store`. El service worker
excluye esas rutas de su caché. Consulta la [guía de validación del MVP](../specs/002-register-income/quickstart.md)
para usar una base aislada.


## IP efectiva para el límite de inicio de sesión

Para habilitar el reenvío, configura `CLIENT_IP_PROXY_TOKEN` con un secreto
aleatorio dedicado en el entorno local, carga el mismo valor en Astro y PHP y
declara en `TRUSTED_API_PROXY_PEERS` la lista de direcciones exactas observadas
por PHP para sus proxies autorizados. Mantén el tramo interno fuera del acceso
público. Desde la raíz, carga el ambiente antes de iniciar Astro con
`set -a`, `source .env`, `set +a` y `npm --prefix frontend run dev`.

Cuando Herd es el punto de entrada que conoce la IP del navegador, configura
el bloque que reenvía el origen público hacia Astro con estas directivas de
Nginx. Sustituye el valor de ejemplo en la configuración local de Herd.

```nginx
proxy_set_header X-Flow-Client-IP $remote_addr;
proxy_set_header X-Flow-Proxy-Token "VALOR_LOCAL_DE_CLIENT_IP_PROXY_TOKEN";
```

Declara la IP exacta desde la que ese proxy conecta a Astro en
`TRUSTED_ASTRO_INGRESS_PEERS`. Astro elimina los encabezados entrantes de
reenvío y emite su propio par para PHP. Solo acepta la IP declarada por un
ingreso autorizado que presente el token. Para acceso directo a Astro toma la
IP del socket. PHP valida el par inmediato y el token antes de usar la IP
emitida por Astro. Si la configuración falta o un dato resulta inválido, el
límite usa la IP de conexión que corresponda. Conserva el token en configuración
privada y reinicia los procesos al cambiarlo.

### Comprobar los contadores a través de Herd

Con Astro y Herd activos, usa dos IP de esta Mac que estén declaradas con
`listen` en el sitio HTTPS de Herd. El ejemplo usa la IP local y la IP Wi-Fi.
Si cambia la IP Wi-Fi, actualiza `lan_ip` y verifica que Herd siga
escuchando en esa dirección. Ejecuta el bloque en zsh desde la Mac.

```zsh
lan_ip=192.168.100.10
probe_dir=$(mktemp -d)
probe_email="herd-probe-$(uuidgen)@example.test"

probe_login() {
  local ip="$1" jar="$probe_dir/$1.cookies" origin="https://flow-balance.test"
  local csrf_token
  local -a endpoint=(--interface "$ip" --resolve "flow-balance.test:443:$ip" --max-time 5)
  csrf_token=$(curl -fsS "${endpoint[@]}" -c "$jar" "$origin/api/auth/csrf" | jq -er .csrfToken) || return 1
  curl -sS "${endpoint[@]}" -b "$jar" -o /dev/null -w '%{http_code}\n' \
    -H "Origin: $origin" -H "X-CSRF-Token: $csrf_token" -H 'Content-Type: application/json' \
    --data "{\"email\":\"$probe_email\",\"password\":\"credencial-ficticia\"}" "$origin/api/auth/login"
}

for attempt in 1 2 3 4 5; do
  printf 'Local %s: ' "$attempt"
  probe_login 127.0.0.1 || break
done
printf 'LAN 1: '
probe_login "$lan_ip"
```

El resultado esperado es `401, 401, 401, 401, 429` para el origen local y
`401` en el primer intento de la LAN con el mismo correo ficticio. Si la LAN
también devuelve `429`, revisa las dos cabeceras de Nginx, el token compartido
y las direcciones de los saltos inmediatos. Cada ejecución usa un correo
ficticio nuevo.
