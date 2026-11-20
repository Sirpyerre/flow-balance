## Purpose

Define cómo GitHub Actions construye, prueba y publica Flow Balance en el VPS subiendo solo lo
indispensable, con credenciales protegidas y reversión rápida.

## ADDED Requirements

### Requirement: Disparo y aprobación
El despliegue a producción MUST ejecutarse solo desde la rama `main` o por acción manual, bajo
un entorno `production` protegido, y MUST requerir que la suite de pruebas pase antes.

#### Scenario: Rama distinta
- **WHEN** se hace push a una rama que no es `main`
- **THEN** corren las pruebas y no se despliega

#### Scenario: Pruebas fallidas
- **WHEN** alguna prueba falla
- **THEN** no se sube ningún archivo al servidor

### Requirement: Construcción fuera del servidor
El frontend y las dependencias de PHP de producción MUST construirse en el runner. El servidor
MUST NOT ejecutar Composer, Node ni compilaciones.

#### Scenario: Paquete listo
- **WHEN** termina la construcción
- **THEN** el paquete contiene el frontend compilado y las dependencias sin las de desarrollo

### Requirement: Lista blanca de archivos
El paquete desplegado MUST contener únicamente: el punto de entrada público, `src/`, `config/`,
las migraciones, `phinx.php`, `composer.json`, `composer.lock`, las dependencias de producción y
el frontend compilado. MUST NOT contener `docs/`, `specs/`, `openspec/`, `tests/`, `.specify/`,
`.agents/`, `.claude/`, `compose.yaml`, `db/seeders/`, `db/bootstrap/`, `frontend/src/`,
`frontend/node_modules/`, archivos `.env` ni informes de pruebas.

#### Scenario: Inspección del paquete
- **WHEN** el pipeline lista el contenido del paquete antes de subirlo
- **THEN** ninguna ruta excluida está presente y el pipeline falla si aparece alguna

### Requirement: Credenciales del pipeline
El pipeline MUST autenticarse en el servidor con una llave SSH exclusiva de despliegue guardada
como secreto, verificar la huella del servidor contra un valor fijado y MUST NOT desactivar la
verificación de host. Los valores no sensibles (host, usuario, origen) MUST ser variables. Los
secretos MUST NOT imprimirse en los registros.

#### Scenario: Huella distinta
- **WHEN** la huella del servidor no coincide con la fijada
- **THEN** el despliegue se aborta antes de transferir nada

#### Scenario: Usuario sin privilegios
- **WHEN** el pipeline ejecuta comandos en el servidor
- **THEN** lo hace con un usuario sin acceso root ni a los archivos del blog

### Requirement: Releases atómicos
Cada despliegue MUST crear un directorio de release nuevo, enlazar el archivo de entorno
compartido y activar el release cambiando un enlace simbólico en una sola operación. El
servidor MUST conservar los 3 releases más recientes.

#### Scenario: Activación
- **WHEN** el release nuevo supera sus comprobaciones
- **THEN** el enlace activo apunta a él y el anterior se conserva

#### Scenario: Limpieza
- **WHEN** existen más de 3 releases
- **THEN** se eliminan los más antiguos y nunca el activo

### Requirement: Respaldo y migración
Antes de aplicar migraciones, el pipeline MUST generar un respaldo de la base de Flow Balance
solamente, y MUST NOT incluir la base del blog. Si la migración falla, el release MUST NOT
activarse.

#### Scenario: Migración fallida
- **WHEN** una migración termina con error
- **THEN** el release anterior sigue activo, el respaldo existe y el pipeline reporta el fallo

#### Scenario: Respaldo vacío o incompleto
- **WHEN** el respaldo termina con error, queda vacío o no acaba con la marca de volcado completo
- **THEN** no se aplica ninguna migración, el release no se activa y el pipeline reporta el fallo

### Requirement: Comprobación y reversión
Tras activar, el despliegue MUST consultar el punto de salud desde el propio servidor, sin pasar
por el proxy público, y, si no responde de forma correcta, MUST restaurar el enlace al release
anterior y reportar el fallo al pipeline.

#### Scenario: Salud fallida
- **WHEN** la comprobación falla tras la activación
- **THEN** el enlace vuelve al release anterior y el pipeline termina en error

#### Scenario: Reversión manual
- **WHEN** un operador solicita volver a la versión previa
- **THEN** puede hacerlo cambiando el enlace sin reconstruir nada
