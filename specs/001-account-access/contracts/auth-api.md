# Contrato HTTP: autenticación y acceso por cuenta

**Base**: `/api`, mismo origen HTTPS que las pantallas Astro. JSON UTF-8; claves y
códigos en inglés, mensajes visibles en español de México. No se usan tokens de
identidad en localStorage ni credenciales de acceso en URL.

## Reglas comunes

- Cookie `__Host-flow_balance`; `HttpOnly; Secure; SameSite=Lax; Path=/`, sin `Domain`.
  Identificador opaco; no incluye datos del usuario. Caducidad máxima de 7 días para
  autenticadas y 15 minutos para anónimas. Las renovaciones nunca superan el límite
  absoluto; el servidor comprueba además 24 horas de inactividad.
- `Cache-Control: no-store, private` en todas las respuestas API, también errores.
  No hay CORS abierto. Validar `Origin` contra `APP_ORIGIN` en operaciones de escritura;
  para navegadores compatibles, ausencia o diferencia de origen produce 403. Fetch
  Metadata puede reforzar la comprobación, sin sustituir el token CSRF.
- Todo POST/PATCH/PUT/DELETE requiere `X-CSRF-Token` válido ligado a la sesión actual.
  Registro y login requieren sesión anónima y token; las escrituras privadas requieren
  primero identidad válida, después CSRF y autorización del recurso. GET no modifica
  datos de negocio. Los cambios internos de sesión/limitador no son operaciones de negocio.
- El frontend usa `credentials: 'same-origin'` y rutas relativas. Captura todos los
  `Set-Cookie` del backend sin unirlos ni convertirlos en cookies del dominio interno.
- Respuesta de error: `{"error":{"code":"validation_failed","message":"Revisa los campos indicados","fields":{"email":"Ingresa un correo válido"}}}`.
  `fields` solo para validación; nunca incluye valores de contraseña ni datos ajenos.
- 400 para JSON ilegible; 415 para tipo de contenido no admitido; 422 para validación;
  401 sin sesión válida; 403 para CSRF/origen; 404 para recurso inexistente o ajeno;
  409 para conflicto de idempotencia; 429 para pausa; 503 ante indisponibilidad de
  persistencia sin confirmar éxito. Limitar tamaño del cuerpo a 16 KiB.
- En API protegida no se redirige a HTML: devolver 401; la pantalla redirige a `/login`
  y limpia los datos privados. No revelar SQL, stack traces ni existencia de recursos ajenos.

## Endpoints de autenticación

| Método y ruta | Entrada | Éxito | Errores de negocio |
|---|---|---|---|
| `GET /auth/csrf` | Cookie opcional | 200, `{ "csrfToken": "..." }`; crea sesión anónima si hace falta | 503 si no puede persistir |
| `POST /auth/register` | `name`, `email`, `password`, `passwordConfirmation`; CSRF | 201, `{ "user": { "id": "1", "name": "Ana", "email": "ana@example.com" } }`; nueva sesión autenticada | 422 validación o correo duplicado |
| `POST /auth/login` | `email`, `password`; CSRF | 200, mismo objeto `user`; regenera identificador | 401 credenciales; 422 campos; 429 pausa |
| `GET /auth/session` | Cookie | 200, objeto `user` de la sesión | 401 sin sesión válida |
| `POST /auth/logout` | Cookie autenticada y CSRF | 204, revocación confirmada y cookie expirada | 401 si ya no es válida; 403 CSRF; 503 sin confirmar cierre |

Las respuestas exitosas de registro, login y estado incluyen además
`session: { idleExpiresAt, absoluteExpiresAt }`, con instantes ISO-8601 UTC, sin
identificador de sesión. Una respuesta financiera autenticada incluye la cabecera
`X-Session-Idle-Expires-At` actualizada para ajustar el temporizador del cliente.
Estos datos solo controlan cuándo ocultar el contenido; el servidor valida los límites.

`GET /auth/session` no renueva inactividad y no se usa como heartbeat que prolongue
la sesión. Antes de mostrar una pantalla privada se comprueba identidad. Al renovar
identidad o registrarse se invalida el identificador anterior y su estado CSRF;
el cliente vuelve a obtener un token de `/auth/csrf`. No se registra contraseña,
confirmación, cookie o CSRF en logs.

Registro: validar límites de FR-002/003, normalizar correo en PostgreSQL, insertar
dentro de un SAVEPOINT de la transacción y convertir exclusivamente la violación de
`users_email_normalized_unique`
en 422 con mensaje “No se pudo crear la cuenta con este correo”, tras hacer
`ROLLBACK TO SAVEPOINT` para dejar utilizable la unidad de trabajo. Otros errores
no se presentan como duplicados. La verificación previa
es solo ayuda; la garantía definitiva es la restricción. Tras commit, redirigir a
`/dashboard`; cuenta nueva ve estado vacío y “Crear mi primer proyecto”.

Login: 401 con `invalid_credentials` y “El correo o la contraseña son incorrectos”
para correo inexistente o contraseña errónea. La clave de limitación es correo
normalizado + IP efectiva de origen. Primeros cuatro fallos: 401. Quinto fallo en la
ventana de 15 minutos: 429 y comienza pausa de 15 minutos. Mientras dure, devolver
`login_temporarily_blocked`, “Demasiados intentos. Intenta de nuevo en 15 minutos”
y `Retry-After` en segundos restantes. La pausa se aplica incluso con contraseña
correcta, no se extiende por reintentos y no depende de que exista la cuenta.
Campos malformados (422) y CSRF inválido (403) no son fallos de credenciales.
El mismo límite cubre solicitudes directas y formularios.

Logout: marcar revocada la sesión en PostgreSQL y eliminar cookie con el mismo
nombre/Path/atributos antes de anunciar éxito. Un fallo de commit no devuelve 204.
Una petición con la sesión anterior no la recrea. Reintento con token ya invalidado
puede devolver 401: no vuelve a activar nada, y el cliente puede confirmar que ya no
hay sesión con `/auth/session`. Otras sesiones independientes siguen vigentes.

## Endpoints mínimos de aislamiento verificable

Estos contratos corresponden a la sección vertical mínima del plan; no sustituyen
la especificación futura del CRUD completo de clientes, proyectos y cobros.

| Método y ruta | Contrato |
|---|---|
| `GET /dashboard` | Identidad requerida; resumen propio sin mezclar monedas. Para la cuenta nueva: colecciones vacías, totales cero y acción de primer proyecto |
| `GET /reports/monthly?month=2026-11&currency=MXN` | Identidad requerida; filtra por `user_id` de sesión y periodo; devuelve mes, moneda, `clientPaidTotal` como cadena decimal y `payments` propios que justifican el total |
| `GET /payments/{id}` | 200 solo si pertenece a la sesión; 404 idéntico para ajeno e inexistente |
| `PATCH /payments/{id}` | Identidad + CSRF; admite cambio de `clientPaidAmount` del cobro propio con validación decimal. 200 con cobro actualizado; ajeno/inexistente: 404 sin cambios |
| `GET /clients/{id}` | Identidad requerida; 200 con `{id, name}` del cliente propio; ajeno/inexistente: mismo 404 |
| `PATCH /clients/{id}` | Identidad + CSRF; admite únicamente `{name}`; 200 con `{id, name}` actualizado |
| `GET /projects/{id}` | Identidad requerida; 200 con `{id, name, clientId}` del proyecto propio; ajeno/inexistente: mismo 404 |
| `PATCH /projects/{id}` | Identidad + CSRF; admite `{name}`, `{clientId}` o ambos; 200 con `{id, name, clientId}` actualizado |

En clientes y proyectos, `name` es una cadena de 1 a 100 caracteres después de
quitar espacios exteriores. Los identificadores se representan como cadenas.
Un PATCH vacío, con campos desconocidos o con `userId` devuelve 422. Tras comprobar
identidad y CSRF, un recurso objetivo ajeno o inexistente devuelve el mismo 404.
En un proyecto propio, un `clientId` ajeno o inexistente produce el mismo error 422
de relación no disponible, sin revelar existencia ni titular. Toda modificación se
valida y aplica de forma atómica; no se permite cambiar el titular.

Representación mínima de reporte:

```json
{
  "month": "2026-11",
  "currency": "MXN",
  "clientPaidTotal": "3000.00",
  "payments": [
    { "id": "101", "clientPaidAmount": "3000.00", "currency": "MXN", "paidOn": "2026-11-10" }
  ]
}
```

No es dinero neto recibido. No se suman monedas distintas. `clientPaidAmount`
se recibe como cadena decimal positiva, hasta 14 dígitos enteros y dos decimales;
mayor precisión, notación exponencial, coma decimal o número JSON se rechazan con
422. No hay redondeo implícito ni conversión a float. Validar moneda y periodo
sin ampliar la titularidad. El recorrido inicial usa MXN y valores exactos con dos
decimales; otros formatos/cálculos se especificarán con los módulos financieros.
El backend rechaza `userId` en el cuerpo o query en lugar de usarlo como filtro de
autorización. No permite cambiar el titular. Las relaciones se validan por cuenta y
las FK compuestas impiden cruzarlas aun ante un error de aplicación.

La creación de fixtures usa servicios reales con `AuthenticatedUser` explícito.
La futura creación de cobros exigirá `Idempotency-Key`, único por cuenta, y devolverá
el mismo cobro al repetir contenido idéntico; un cambio bajo la misma clave es 409.
La base mínima ya reserva esa restricción; no se añade aquí una interfaz general de cobros.

## Contrato de pantallas y caché

Astro sirve `/register`, `/login`, `/dashboard` y el punto de entrada `/projects/new`.
Formularios semánticos con etiquetas, mensajes asociados, foco en primer error,
`autocomplete=name`, `email`, `new-password` y `current-password`; permitir pegado,
Enter y gestores. Una isla React para estado de envío/errores de cada formulario
es válida; no se hidratan layouts o páginas enteras sin necesidad. `lang=es-MX`.

Las páginas Astro son shells públicos sin datos personales prerenderizados. El
contenido privado permanece oculto hasta validar sesión y cargar datos. Las respuestas
HTML de rutas privadas se marcan no-store desde el servidor de entrada, incluso si
el shell es estático. El service worker no guarda esas rutas ni `/api` y no usa un
fallback que incluya información privada.

El build compila `frontend/src/service-worker.ts` y emite `/service-worker.js`.
El registro usa esa URL con alcance `/`. El servidor entrega JavaScript, sin
redirecciones ni fallback HTML. La lista positiva de recursos públicos corresponde
a los archivos emitidos por el build. La validación PWA también se ejecuta sobre
el resultado compilado, servido mediante HTTPS bajo el mismo origen que `/api`;
comprueba registro, activación y control tras recargar, además de las exclusiones
de caché tras reapertura y pérdida de conexión.

En salida/cambio de cuenta/401 se vacía el estado financiero y se comunica la salida a
otras pestañas mediante `BroadcastChannel`. En `pagehide` se oculta y limpia el DOM
privado; en `pageshow` (incluido bfcache), foco tras volver a la app o reconexión se
revalida antes de mostrar contenido. Sin conexión se muestra acceso no disponible,
no una copia financiera anterior. No se hace polling de datos para mantener sesión.
Un temporizador local oculta contenido al alcanzar el menor vencimiento informado,
sin hacer peticiones para prolongarlo. El servidor conserva la última palabra aunque
no llegue una notificación entre pestañas.
