# Configuración local

Copia `.env.example` a `.env` y define contraseñas distintas para PostgreSQL,
desarrollo y pruebas. Genera los secretos de aplicación de forma independiente
con `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`.

Las variables del proceso tienen precedencia sobre el archivo local. Usa
`APP_ENV=testing` con las variables `TEST_DB_*` para las pruebas. Consulta la
[guía de arranque](../specs/001-account-access/quickstart.md) para los orígenes
HTTPS de Herd y la configuración del proxy.
