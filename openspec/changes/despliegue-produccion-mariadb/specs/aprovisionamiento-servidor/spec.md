## Purpose

Define cómo se prepara el VPS para Flow Balance de forma reproducible sin afectar al blog Drupal que
comparte el servidor, y cómo se respaldan sus datos hacia el equipo del operador.

## ADDED Requirements

### Requirement: Aprovisionamiento acotado e idempotente
El aprovisionamiento de Flow Balance MUST ejecutarse con un playbook propio que solo cree o modifique
los recursos de Flow Balance: su usuario y directorios, su base y sus usuarios de MariaDB, su
`shared/.env`, su pool de PHP-FPM, su vhost y la herramienta de volcado. MUST NOT modificar
`nginx.conf`, `php.ini`, el pool del blog, `sshd`, el cortafuegos ni la base del blog, MUST NOT
actualizar paquetes ni reiniciar servicios, y una segunda ejecución MUST NOT cambiar nada.

#### Scenario: Segunda ejecución
- **WHEN** el playbook se ejecuta dos veces seguidas sin cambios en el servidor
- **THEN** la segunda ejecución informa cero cambios

#### Scenario: Archivos ajenos
- **WHEN** el playbook termina
- **THEN** los archivos de configuración globales de Nginx, PHP y SSH y la base del blog son idénticos a los de antes

#### Scenario: Configuración inválida
- **WHEN** `nginx -t` o `php-fpm8.3 -t` rechazan la configuración generada
- **THEN** el playbook retira lo instalado, no recarga nada, falla y el blog sigue sirviendo

#### Scenario: Requisitos ausentes
- **WHEN** falta el certificado de origen, el nombre de host, el acceso administrativo a MariaDB, o la configuración de Nginx no es válida o no hereda el `real_ip` del blog
- **THEN** el playbook aborta antes de modificar nada e indica qué falta

#### Scenario: Privilegios ajenos
- **WHEN** el usuario de MariaDB de Flow Balance ya tiene privilegios fuera de su base
- **THEN** el playbook se detiene sin revocarlos ni modificar nada de la base y lo informa

#### Scenario: Privilegio acotado
- **WHEN** el playbook concede los privilegios del usuario de MariaDB
- **THEN** el nombre de la base se escribe con el guion bajo escapado, de modo que no alcanza a otras bases de nombre parecido

### Requirement: Secretos generados y conservados en el servidor
`APP_SECRET` y `DB_PASSWORD` MUST generarse en el servidor, escribirse solo en `shared/.env` con modo
600 y MUST NOT pasar por el equipo del operador, por GitHub ni por la salida del playbook. Un
`shared/.env` existente MUST NOT sobrescribirse ni rotarse.

#### Scenario: Primera ejecución
- **WHEN** `shared/.env` no existe
- **THEN** se genera con secretos nuevos en modo 600, el usuario de MariaDB queda con esa contraseña y la salida no muestra ningún valor

#### Scenario: Archivo existente
- **WHEN** `shared/.env` ya existe
- **THEN** su contenido no cambia y solo se comprueba que el usuario de MariaDB exista con esa contraseña

### Requirement: Usuario único de despliegue
Un solo usuario de sistema, `flowdeploy`, MUST ejecutar el pool, ser dueño del código y recibir los
despliegues y los respaldos por SSH. MUST tener shell y MUST NOT tener sudo, contraseña ni pertenecer
al grupo del blog, y la llave del pipeline MUST llevar la opción `restrict`.

#### Scenario: Aislamiento del blog
- **WHEN** `flowdeploy` intenta leer los archivos de Drupal
- **THEN** el sistema lo deniega

#### Scenario: Sin privilegios
- **WHEN** `flowdeploy` consulta sus privilegios de `sudo`
- **THEN** no tiene ninguno

### Requirement: Respaldo local verificado sin copia en el servidor
El respaldo manual MUST ejecutarse desde el equipo del operador, recibir el volcado comprimido de la
base de Flow Balance por la conexión SSH directamente a disco local y MUST NOT escribir ninguna copia
en el servidor. El archivo MUST verificarse localmente (integridad de la compresión, marca de volcado
completo, tamaño mínimo y las tablas esperadas) y MUST descartarse si alguna comprobación falla.

#### Scenario: Respaldo correcto
- **WHEN** el operador ejecuta el respaldo y el volcado llega completo
- **THEN** queda un archivo local verificado y el servidor no conserva ninguna copia nueva

#### Scenario: Volcado incompleto o fallido
- **WHEN** las credenciales fallan, el volcado queda vacío, no termina con la marca de volcado completo o la conexión se corta
- **THEN** no queda ningún archivo local parcial y el script termina con error

#### Scenario: Respaldo antiguo
- **WHEN** el último respaldo local tiene más de 7 días
- **THEN** el script lo avisa al ejecutarse

### Requirement: Restauración comprobable
El proyecto MUST incluir un procedimiento que restaure el último respaldo local en una base desechable,
compruebe que se restauran sin errores las tablas y restricciones del esquema, permita compararlo tabla
por tabla con una base local indicada por el operador y elimine la base desechable al terminar. La base
de producción no es accesible desde el equipo del operador, por lo que sin base de comparación se
comprueba el esquema esperado y se informan las filas de cada tabla.

#### Scenario: Restauración fiel
- **WHEN** se restaura un respaldo válido
- **THEN** se restauran todas las tablas y restricciones del respaldo y, si se indicó una base de comparación, el contenido de cada tabla coincide con ella

#### Scenario: Diferencia con la base de comparación
- **WHEN** la base restaurada difiere en algún dato de la base de comparación
- **THEN** el procedimiento informa la tabla distinta y termina con error

#### Scenario: Respaldo truncado
- **WHEN** el respaldo está truncado
- **THEN** la restauración falla y la base desechable se elimina
