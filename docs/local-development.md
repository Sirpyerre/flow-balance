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
