## Purpose

Especifica cómo se sirve Flow Balance en el VPS compartido con el blog Drupal, sin que ninguno
de los dos afecte al otro y preservando las respuestas privadas sin caché.

## ADDED Requirements

### Requirement: Host propio con HTTPS
La aplicación MUST servirse desde su propio nombre de host bajo HTTPS válido, con redirección de
HTTP a HTTPS. El frontend y `/api` MUST compartir el mismo origen.

#### Scenario: Acceso por HTTP
- **WHEN** se solicita la aplicación por HTTP
- **THEN** la respuesta redirige a la misma ruta por HTTPS

#### Scenario: Mismo origen
- **WHEN** el navegador carga la interfaz y llama a `/api/auth/session`
- **THEN** ambas solicitudes van al mismo origen y la cookie se envía

### Requirement: Cloudflare delante del origen
El nombre de host de la aplicación MUST resolverse a través del proxy de Cloudflare con el modo
SSL/TLS en Full (strict), usando un certificado de origen válido para ese nombre. La
configuración de Cloudflare para el host MUST omitir la caché de todo el contenido excepto
`/_astro/*`, y MUST tener desactivadas las funciones que reescriben HTML o JavaScript (Rocket
Loader, Auto Minify, Email Obfuscation).

#### Scenario: Vista privada tras el proxy
- **WHEN** se solicita `/dashboard` a través de Cloudflare
- **THEN** la respuesta no se sirve desde la caché de Cloudflare y conserva
  `Cache-Control: private, no-store`

#### Scenario: Certificado de origen
- **WHEN** Cloudflare se conecta al origen en modo Full (strict)
- **THEN** el certificado presentado cubre el nombre de host de la aplicación

### Requirement: IP real del cliente detrás de Cloudflare
La aplicación MUST recibir en `REMOTE_ADDR` la IP del visitante, tomada de `CF-Connecting-IP`
únicamente cuando la conexión proviene de un rango de Cloudflare. Un encabezado
`CF-Connecting-IP` enviado desde cualquier otra dirección MUST ignorarse. El limitador de inicios
de sesión MUST contar los intentos por la IP del visitante.

#### Scenario: Encabezado falsificado
- **WHEN** una solicitud llega desde una dirección fuera de los rangos de Cloudflare con un
  `CF-Connecting-IP` arbitrario
- **THEN** `REMOTE_ADDR` conserva la dirección real de la conexión

#### Scenario: Visitantes distintos
- **WHEN** dos visitantes con IP distintas llegan por el mismo borde de Cloudflare
- **THEN** los intentos fallidos de inicio de sesión de uno no bloquean al otro

### Requirement: Enrutamiento estático y API
Las rutas que no empiezan por `/api` MUST servirse como archivos estáticos compilados, y las
que empiezan por `/api` MUST llegar a la aplicación PHP. Ninguna otra ruta PHP MUST ser
ejecutable.

#### Scenario: Archivo PHP arbitrario
- **WHEN** se solicita un archivo `.php` distinto del punto de entrada
- **THEN** la respuesta es 404 y no se ejecuta código

#### Scenario: Archivo oculto
- **WHEN** se solicita `.env`, `.git` o `composer.json`
- **THEN** la respuesta es 404

### Requirement: Respuestas privadas sin caché
Las rutas `/dashboard`, `/clients`, `/projects`, `/payments` y todo `/api` MUST responder con
`Cache-Control: private, no-store`, y `/service-worker.js` MUST responder con `no-cache`. Los
archivos con hash bajo `/_astro/` MAY cachearse como inmutables. Ninguna respuesta de Flow
Balance MUST entrar en la caché FastCGI del blog.

#### Scenario: Vista autenticada
- **WHEN** se solicita `/dashboard`
- **THEN** la respuesta lleva `Cache-Control: private, no-store`

#### Scenario: Activo con hash
- **WHEN** se solicita un archivo bajo `/_astro/`
- **THEN** la respuesta es cacheable por largo plazo

#### Scenario: Aislamiento de caché
- **WHEN** se inspecciona la configuración efectiva de Nginx
- **THEN** el vhost de la aplicación no usa la zona de caché del blog

### Requirement: Pool PHP-FPM dedicado
La aplicación MUST ejecutarse en un pool propio con usuario y socket propios, creación bajo
demanda y un máximo de 2 procesos hijos, con límites de memoria y de tiempo inferiores a los del
pool del blog. El tamaño máximo de cuerpo de la solicitud MUST ser pequeño (1 MB o menos).

#### Scenario: Carga simultánea
- **WHEN** llegan más de 2 solicitudes simultáneas
- **THEN** las excedentes esperan en cola y el pool del blog no se ve afectado

#### Scenario: Cuerpo excesivo
- **WHEN** se envía un cuerpo de más de 1 MB a `/api`
- **THEN** la respuesta es 413

### Requirement: Encabezados de seguridad
Las respuestas MUST incluir `Strict-Transport-Security`, `X-Content-Type-Options: nosniff` y una
política de referente restrictiva.

#### Scenario: Respuesta cualquiera
- **WHEN** se solicita la página de inicio
- **THEN** los tres encabezados están presentes

### Requirement: Publicación sin reinicio del blog
Publicar una nueva versión de la aplicación MUST NOT reiniciar ni recargar Nginx ni PHP-FPM, y
el código nuevo MUST empezar a servirse sin esperar la revalidación de OPcache.

#### Scenario: Nuevo release
- **WHEN** se cambia el enlace al release activo
- **THEN** la siguiente solicitud ejecuta el código nuevo y el blog no se interrumpe
