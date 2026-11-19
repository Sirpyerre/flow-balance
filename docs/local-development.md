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
para las cabeceras y la configuración de Herd. Actualmente el limitador usa la IP
del par que llega a PHP. Queda pendiente validar la propagación confiable de la IP
del navegador con los proxies de Herd y Astro.

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
