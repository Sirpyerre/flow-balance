# Plan de implementación: autenticación y migraciones de Flow Balance

**Rama**: `main` (sin rama nueva) | **Fecha**: 2026-11-18
**Especificación**: [spec.md](spec.md)

**Entrada**: Especificación de acceso por cuenta, aclaraciones de sesión y limitación
más las decisiones técnicas de autenticación, PostgreSQL, Herd y Phinx del usuario.

## Resumen

Mezzio será la única autoridad de registro, login, logout y autorización. Astro sirve
pantallas en español de México; React se limita a islas que justifiquen estado
interactivo. El navegador ve un solo origen HTTPS, con `/api` enrutado a PHP de Herd.
PostgreSQL conserva cuentas, sesiones, limitación de intentos y los datos protegidos.
Phinx versiona cada cambio de esquema mediante la conexión compartida de la aplicación.

La investigación está en [research.md](research.md), el modelo en
[data-model.md](data-model.md), los contratos en [contracts/](contracts/) y los pasos
de ejecución y validación en [quickstart.md](quickstart.md). Esta entrega es de diseño:
no instala aplicaciones, configura Herd ni ejecuta Docker o migraciones.

## Contexto técnico

| Aspecto | Decisión |
|---|---|
| Lenguajes | PHP 8.4 y TypeScript; identificadores/comentarios en inglés |
| Backend | Laminas Mezzio 3, PSR-7/PSR-15, contenedor PSR-11, PDO PostgreSQL |
| Frontend | Astro estable con integración React compatible; versiones exactas en lockfiles al implementar |
| Autenticación | `mezzio-session`, adaptador PostgreSQL y `mezzio-csrf`; hashing PHP Argon2id |
| Persistencia | PostgreSQL 17, UTF-8, locale fijo; Docker Compose solo para PostgreSQL |
| Migraciones | `robmorgan/phinx`, `phinx.php`, `db/migrations`, conexión compartida |
| Desarrollo | PHP-FPM de Herd; Astro en Node 22.23 o versión compatible superior; Composer 2 |
| Pruebas | PHPUnit para unidad/integración; Playwright para web/PWA; PostgreSQL real independiente |
| Destino | Navegador escritorio/móvil y PWA; contenido privado requiere conexión |
| Tipo de proyecto | Aplicación web con backend PHP y frontend Astro en repositorio único |
| Alcance inicial | Cuenta personal sin roles, compartición, recuperación ni MFA; dos usuarios bastan para el escenario de aislamiento |
| Capacidad | Una instancia inicial y cargas funcionales; no se promete volumen o SLA sin medición |
| Objetivos | Registro ≤2 minutos y acceso ≤1 minuto para 4/5 participantes, según SC-006; pruebas de exactitud y aislamiento sin tolerancia a errores |
| Restricciones | Un origen HTTPS, sin caché financiera offline; sesiones 24 h/7 días; 5 fallos/15 min → pausa 15 min |

No quedan preguntas técnicas bloqueantes. La elección exacta de versiones compatibles,
el coste de Argon2id y los límites operativos se verifican durante instalación y medición,
sin cambiar los requisitos funcionales.

## Verificación de la constitución

Puerta evaluada antes de investigar y reevaluada tras el diseño. Se aplica la
constitución vigente de “Ingresos Freelance” al proyecto Flow Balance.

| Principio | Comprobación prevista | Antes | Después |
|---|---|---|---|
| I. Especificación primero | spec validada y aclaraciones registradas; tareas se generan después | Cumple | Cumple |
| II. Exactitud monetaria | NUMERIC, cadenas decimales, sin float; sumas exactas en prueba de dos cuentas | Cumple | Cumple |
| III. Conceptos independientes | `clientPaidAmount` no se presenta como monto acordado o recibido neto; estados de entrega/cobro separados | Cumple | Cumple |
| IV. Trazabilidad/idempotencia | reporte con cobros propios; restricción de idempotencia al incorporar tabla payments | Cumple | Cumple |
| V. Dominio comprobable | servicios sin Mezzio/Astro; reloj inyectable; integración real para propiedad, sesiones y migraciones | Cumple | Cumple |
| VI. Móvil y privacidad | formularios de una etapa; aislamiento backend, PWA sin finanzas en caché | Cumple | Cumple |
| VII. Idioma | código, SQL y pruebas en inglés; UI es-MX y Spec Kit español | Cumple | Cumple |
| VIII. Vocabulario | `Payment`, `platformFee`, `outstandingBalance`; columnas con snake_case del mismo vocabulario | Cumple | Cumple |
| IX. Comentarios | explicar restricciones/motivos; no narrar firmas o pasos | Cumple | Cumple |
| X. Responsabilidades | handlers adaptan HTTP; servicios aplican negocio; dependencias explícitas | Cumple | Cumple |
| XI. Reutilización | una normalización de correo, una política de identidad, repositorios filtrados reutilizados en detalle/reporte | Cumple | Cumple |

Cada revisión de implementación verifica las once reglas, incluidas convenciones de
idioma, vocabulario, comentarios, responsabilidad y reutilización. Las pruebas
financieras avanzadas (comisiones, conversiones, saldos y cambios de mes completos)
se incorporan con sus reglas de dominio; este módulo no inventa esos cálculos.
El reporte mínimo sí prueba límite de periodo y suma exacta sin mezclar monedas.

## Estructura del proyecto

### Documentación de esta funcionalidad

```text
specs/001-account-access/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── auth-api.md
│   └── development-routing.md
└── checklists/requirements.md
```

`tasks.md` será salida de `/speckit-tasks`; no se genera en este paso.

### Código fuente previsto en la raíz

```text
composer.json
composer.lock
phinx.php
compose.yaml
.env.example
config/
├── database.php
├── container.php
├── pipeline.php
└── routes.php
public/index.php
src/
├── Identity/
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
├── Finance/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
└── Shared/
    ├── Database/
    └── Clock/
db/
├── migrations/
└── bootstrap/
frontend/
├── playwright.config.ts
├── package.json
├── package-lock.json
├── astro.config.mjs
├── public/manifest.webmanifest
└── src/
    ├── pages/
    ├── components/
    ├── lib/
    └── service-worker.ts
tests/
├── Unit/
├── Integration/
├── Fixtures/
└── E2E/
```

Solo `specs/`, configuración Spec Kit y `AGENTS.md` existen actualmente. El árbol es
el diseño a crear, no un inventario de código implementado. `public/` es la raíz
web PHP para el enlace de Herd; `frontend/` es independiente de ese document root.
`.gitignore` hoy excluye Compose/Docker: al implementar se debe permitir versionar
`compose.yaml`, manteniendo secretos en `.env` fuera de Git.

## Fase 0: decisiones de investigación

[research.md](research.md) resuelve hashing largo, normalización SQL única, persistencia
de sesión, CSRF, mismo origen, Phinx y validación real. Las alternativas y fuentes
oficiales están registradas. PHP instalado de Herd soporta Argon2id y PostgreSQL;
el wrapper `herd php` falla en la inspección local, por lo que quickstart documenta
el binario explícito. No se modifica configuración global del equipo en esta fase.

## Fase 1: diseño de autenticación

1. **Servicios y adaptadores**: `RegisterUser`, `LoginUser`, `LogoutUser`, `SessionPolicy`,
   `LoginThrottle` y `AuthenticatedUser`, con repositorios y reloj explícitos. Handlers
   validan/transforman entrada y salida; no contienen reglas monetarias ni de identidad.
   `PasswordHasher` adapta las tres funciones PHP; no se agrega hashing propio.
2. **Sesiones**: adaptador a la interfaz oficial de Mezzio, PostgreSQL, cookie host-only
   `__Host-flow_balance`, HttpOnly, Secure, SameSite=Lax. Login y registro llaman a
   regeneración, usan el contenedor retornado e invalidan el anterior. Timestamps
   y bloqueos transaccionales siguen el modelo; no depender de TTL del navegador.
3. **Caducidad y concurrencia**: 24 horas sin actividad y 7 días absolutos, con pruebas
   justo antes/en el límite. Estado y datos de negocio comparten unidad de trabajo;
   ningún 2xx sale antes del commit. La revocación se comprueba de nuevo después del
   bloqueo y una petición concurrente no recrea la sesión anterior.
   La API del navegador y la PWA usan esta misma sesión mediante cookie; no requieren
   JWT por ser una API. La identidad y autorización se verifican en cada petición
   privada. JWT se reconsiderará si aparecen consumidores externos o una necesidad
   concreta de delegación de acceso; no se añade al alcance actual.
4. **CSRF**: sesión antes de middleware CSRF; token ligado a sesión, validado en toda
   escritura, incluidos login/registro/logout. Origen público explícito. Después de
   autenticar se obtiene nuevo token. Fallos de CSRF no ejecutan acciones de negocio.
5. **Limitación**: correo normalizado + IP efectiva, decisión atómica en PostgreSQL;
   quinto fallo activa pausa. No confiar en la cabecera HTTP `Origin` como IP ni
   en forwarding sin sanear. Registro duplicado resuelto por unicidad SQL, no por carrera
   entre una consulta previa y un insert. Aislar errores SQL esperados con savepoints
   y revertir al savepoint antes de seguir con la persistencia de sesión.
6. **Autorización**: extraer identidad exclusivamente de sesión; métodos de repositorio
   exigen `userId` desde `AuthenticatedUser`; filtrar SELECT/UPDATE y SUM. Rechazar
   `userId` del cliente; comparar relaciones de cuenta y protegerlas también con FK.
   Mismo 404 para ajeno/inexistente; sin efectos parciales.
7. **UI y PWA**: pantallas Astro, islas acotadas para envío/errores; autocompletado,
   gestores y formularios accesibles. Shell sin datos prerenderizados, carga privada
   solo tras validar sesión. No-store y exclusiones positivas del SW; borrar contenido
   al salir/ocultarse la página y revalidar al volver. Ninguna respuesta financiera
   llega a Cache Storage, IndexedDB o localStorage.
   El build compila `frontend/src/service-worker.ts` a `/service-worker.js`, con
   registro de alcance `/`, respuesta JavaScript sin redirecciones ni fallback HTML
   y lista positiva de assets emitidos. La validación comprueba registro, activación,
   control tras recargar y privacidad sobre el build servido por HTTPS con `/api`
   bajo el mismo origen, además del entorno de desarrollo.
8. **Observabilidad**: logs con request ID, categoría de resultado y estado, sin
   contraseñas, cookies, tokens, cuerpos financieros ni correos completos. Métricas
   agregadas de login fallido, pausas, denegaciones y fallos de migración; no son
   un nuevo requisito de panel ni cambian los mensajes públicos.

Orden lógico del pipeline: errores/no-store y límites de cuerpo → saneamiento de
proxy/host → ruta y cuerpo JSON → sesión/unidad de trabajo → identidad y vencimiento
si la ruta es privada → comprobación de Origin/CSRF si es escritura → handler con
servicio autorizado → persistencia/commit → respuesta. 401/403/404/429 mantienen las
cabeceras de privacidad. Operaciones de dominio usan parámetros PDO preparados.

## Fase 1: migraciones y datos protegidos

- `DatabaseConfig` resuelve variables por ambiente y construye PDO; app y `phinx.php`
  usan la misma fábrica. Phinx recibe `connection` y `name`. Sin credenciales duplicadas
  ni default silencioso de pruebas a desarrollo. `version_order=execution`.
- Primera migración `CreateUsers`: campos e índice único definidos en el modelo.
  Después, estado de autenticación. Crear migración nueva para cada cambio;
  Phinx no se ejecuta desde un handler HTTP ni al iniciar cada petición.
- Las migraciones de clientes/proyectos/cobros deberán incluir user_id y las FK
  compuestas desde el primer momento en que se incorporen esas tablas.
- Como hoy esos módulos no existen, la prueba solicitada exige una sección vertical
  mínima: tablas básicas con pertenencia, servicios de fixtures, reporte mensual y
  consulta/modificación acotada de cobro. Usa repositorios/handlers reales de producción,
  no mocks ni tablas diferentes solo para pruebas. No construye su CRUD completo ni
  calcula comisiones, impuestos, conversión o saldo pendiente. Los contratos delimitan
  el importe abonado del ejemplo. El flujo completo del primer proyecto continúa
  dependiendo de su módulo, como declara la especificación.
  Para acreditar también el aislamiento de clientes y proyectos, los contratos
  definen GET/PATCH mínimos por identificador antes de implementar: nombre propio
  editable y, en proyectos, cambio de cliente únicamente dentro de la misma cuenta.
  No se añaden endpoints generales de creación o eliminación.
- `down()` de tablas con filas aborta salvo preservación documentada. Cambios
  destructivos con respaldo/restauración verificada o estrategia expandir/copiar/validar/
  retirar. Un `down()` que recrea tabla vacía no restaura registros.
- Compose expone PostgreSQL solo en loopback, puerto 54329, volumen persistente y
  healthcheck. Base `flow_balance` y otra `flow_balance_test`, con roles distintos
  sin CONNECT cruzado. Los scripts de inicialización crean bases/roles, no el esquema
  de aplicación: ese esquema lo crea Phinx. Para volumen existente, aprovisionamiento
  explícito; no asumir que initdb vuelve a correr.

## Validación de aceptación técnica

| Criterio | Prueba prevista | Evidencia necesaria |
|---|---|---|
| Base vacía y registro | aplicar migraciones, registrar cuenta por HTTP con CSRF | 201, hash Argon2id y tablero vacío |
| Segunda aplicación | volver a ejecutar migrate | ninguna versión repetida; mismo esquema y filas |
| Correo único | insertar variantes por HTTP y SQL, además de dos registros concurrentes | una cuenta; 422 al duplicado; restricción SQL activa |
| Reversión | base exclusiva vacía: migrate, rollback de última, migrate | vuelve mismo esquema; historial correcto |
| Datos existentes | rollback destructivo con filas sin respaldo | aborta sin perder registros |
| Aislamiento real | Ana 3000, Luis 800; GET reportes, GET/PATCH cobro ajeno | totales propios, 404, valor de Ana intacto |
| Pertenencia SQL | intentar relacionar pago/proyecto/cliente de titulares distintos | FK rechaza; no cambios parciales |
| Regeneración y salida | replay de cookie anterior y carrera con petición abierta | 401, sin resurrección ni cambios |
| CSRF y origen | escritura sin token, token ajeno u Origin distinto | 403 y sin efectos |
| Pausa y sesión | reloj inyectable y solicitudes concurrentes | 5/15/15 y límites 24 h/7 días exactos |
| PWA y navegación | salir, Atrás, otra pestaña, offline, otra cuenta | ningún dato residual ni respuesta privada cacheada |

Pruebas Unit no dependen de Mezzio. Integration usa PostgreSQL real y el pipeline
HTTP completo. E2E verifica HTTPS, cookie y proxy, gestores/autocompletado y UI móvil.
No simular Argon2id ni autorización en pruebas de aceptación. Separar pruebas lentas
de concurrencia y usabilidad sin sustituirlas por asserts sobre mocks.

## Seguimiento de complejidad

No hay violaciones constitucionales que requieran excepción. Dos decisiones requieren
revisión de implementación: el adaptador PostgreSQL de sesiones se limita al contrato
oficial y tiene pruebas de revocación/concurrencia; la sección mínima financiera existe
solo para acreditar aislamiento real y no debe crecer hasta convertirse en el diseño
del módulo financiero. Ningún framework de UI ni motor de sesiones adicional.

## Salida y siguiente fase

Diseño y puertas constitucionales completos. `AGENTS.md` debe apuntar a este archivo.
Siguiente comando: `/speckit-tasks`, que ordenará configuración, migraciones,
autenticación, sección mínima de aislamiento y pruebas. No se implementa código ni se
presentan criterios como ejecutados en esta fase.
