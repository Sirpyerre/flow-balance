## Purpose

Garantiza que Flow Balance conserve sus invariantes de cuenta, dinero, idempotencia y
trazabilidad cuando la base de datos es MariaDB 10.11, el motor del servidor de producción.

## ADDED Requirements

### Requirement: Esquema reproducible en MariaDB
Las migraciones MUST crear el esquema completo sobre una base MariaDB 10.11 vacía con juego de
caracteres `utf8mb4`, y MUST poder ejecutarse desde cero sin intervención manual. Las
restricciones `CHECK` existentes MUST seguir aplicándose.

#### Scenario: Base vacía
- **WHEN** se ejecuta el comando de migración contra una base MariaDB vacía
- **THEN** todas las tablas, índices y restricciones existen y el estado de migraciones no
  reporta pendientes

#### Scenario: Restricción violada
- **WHEN** se intenta insertar un cobro con tipo de cambio fuera del rango permitido o con más
  de seis decimales
- **THEN** la base rechaza la escritura y no queda ningún dato parcial

### Requirement: Importes decimales exactos
Todo importe y tipo de cambio MUST almacenarse y calcularse como decimal exacto, sin pasar por
coma flotante, y MUST redondear a dos decimales con el mismo resultado que el comportamiento
actual. Cada importe MUST conservar su moneda.

#### Scenario: Conversión de USD a MXN
- **WHEN** se registra un cobro de 100.005 USD con tipo de cambio 17.123456
- **THEN** el importe en MXN es el mismo que producía la versión anterior de la aplicación

#### Scenario: Agregados mensuales
- **WHEN** se consulta el reporte de un mes con cobros en MXN y USD
- **THEN** los totales de cobrado, comisiones, impuestos y neto coinciden con los de la versión
  anterior, expresados como texto decimal

### Requirement: Idempotencia de cobros bajo concurrencia
Registrar un cobro o un cargo con la misma clave de idempotencia de la misma cuenta MUST crear
a lo sumo un registro, incluso con solicitudes simultáneas hacia distintos proyectos. Una
repetición con el mismo contenido MUST devolver el resultado original; con contenido distinto
MUST rechazarse como conflicto.

#### Scenario: Solicitudes simultáneas
- **WHEN** dos solicitudes con la misma clave llegan al mismo tiempo
- **THEN** existe exactamente un cobro y ambas respuestas identifican ese mismo cobro

#### Scenario: Misma clave, contenido distinto
- **WHEN** se reutiliza una clave con otro importe
- **THEN** la respuesta es un conflicto y no se modifica el cobro original

### Requirement: Pertenencia por cuenta
Toda lectura y escritura de clientes, proyectos y cobros MUST estar limitada a la cuenta
autenticada, sin cambios respecto del comportamiento actual.

#### Scenario: Recurso de otra cuenta
- **WHEN** una cuenta solicita un recurso que pertenece a otra
- **THEN** la respuesta es de no encontrado y no revela su existencia

### Requirement: Fechas y zona horaria en UTC
Los instantes MUST almacenarse en UTC y la conexión MUST fijar la zona horaria de sesión en UTC.
Las expiraciones de sesión y de bloqueo de inicio de sesión MUST conservar su duración actual.

#### Scenario: Sesión inactiva
- **WHEN** una sesión supera su límite de inactividad
- **THEN** la siguiente solicitud autenticada responde que se requiere iniciar sesión

### Requirement: Correo único sin distinguir mayúsculas
La unicidad de correo electrónico MUST evaluarse sin distinguir mayúsculas y minúsculas.

#### Scenario: Registro duplicado
- **WHEN** se registra `Ana@Example.com` y ya existe `ana@example.com`
- **THEN** el registro se rechaza con el mismo error que hoy

### Requirement: Modo estricto
La conexión MUST operar en modo SQL estricto: un valor que no cabe en su columna MUST producir
un error y no truncarse.

#### Scenario: Texto demasiado largo
- **WHEN** se envía un nombre que excede el límite de su columna
- **THEN** la validación responde con error y no se guarda un valor recortado

### Requirement: Suite de pruebas contra MariaDB
Las pruebas de integración MUST ejecutarse contra una base MariaDB independiente de la de
desarrollo y de producción. La suite MUST pasar completa antes de cualquier despliegue.

#### Scenario: Base de pruebas protegida
- **WHEN** las pruebas se ejecutan con credenciales distintas a la base de pruebas dedicada
- **THEN** la configuración se rechaza antes de abrir la conexión
