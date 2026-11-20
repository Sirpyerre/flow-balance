## Purpose

Define cómo la aplicación recibe su configuración y sus secretos en producción, y cómo falla de
forma segura cuando falta o es inválida.

## ADDED Requirements

### Requirement: Entorno de producción
La aplicación y las migraciones MUST aceptar `APP_ENV=production`, usando las variables `DB_*`
y no las `TEST_DB_*`. Un valor de entorno desconocido MUST seguir rechazándose.

#### Scenario: Producción válida
- **WHEN** el entorno es `production` y todas las variables requeridas están definidas
- **THEN** la aplicación se conecta a su base y atiende solicitudes

#### Scenario: Entorno desconocido
- **WHEN** `APP_ENV` tiene un valor no soportado
- **THEN** la aplicación se niega a iniciar y no abre ninguna conexión

### Requirement: Secretos solo en el servidor
`APP_SECRET`, la contraseña de base de datos y cualquier token interno MUST leerse del entorno
del servidor y MUST NOT existir en el repositorio, en artefactos del pipeline ni en registros.
El archivo que los contiene MUST ser legible solo por el usuario que ejecuta la aplicación.

#### Scenario: Revisión del repositorio
- **WHEN** se busca el valor de cualquier secreto en el historial y en el paquete desplegado
- **THEN** no aparece

#### Scenario: Permisos del archivo
- **WHEN** se inspeccionan los permisos del archivo de entorno en el servidor
- **THEN** el modo es 600 y su dueño es el usuario del pool dedicado

### Requirement: Fallo seguro sin configuración
Si falta una variable requerida o `APP_SECRET` tiene menos de 32 caracteres, la aplicación MUST
responder con un error genérico sin revelar valores ni rutas.

#### Scenario: Secreto corto
- **WHEN** `APP_SECRET` tiene 20 caracteres y se intenta iniciar sesión
- **THEN** la respuesta es 503 con un mensaje genérico y no se emite ninguna cookie

### Requirement: Origen público
`APP_ORIGIN` MUST definir el origen HTTPS público y MUST usarse para validar el origen de las
solicitudes que modifican datos.

#### Scenario: Origen distinto
- **WHEN** llega una solicitud de escritura con un `Origin` diferente de `APP_ORIGIN`
- **THEN** se rechaza

### Requirement: Cookie de sesión de producción
La cookie de sesión MUST conservar el prefijo `__Host-`, `Secure`, `HttpOnly`, `SameSite=Lax` y
`Path=/`, y MUST NOT incluir `Domain`.

#### Scenario: Inicio de sesión
- **WHEN** un usuario inicia sesión por HTTPS en el subdominio de la aplicación
- **THEN** la respuesta fija la cookie con esos atributos
