## Purpose

Permite comprobar, sin modificar nada, que el VPS cumple los requisitos técnicos de Flow Balance
antes del primer despliegue y después de cambios en el servidor.

## ADDED Requirements

### Requirement: Verificación de solo lectura
El script de verificación MUST ejecutarse por SSH sin modificar el servidor: no MUST escribir
archivos, cambiar configuración, reiniciar servicios ni crear usuarios o bases. Tampoco MUST
imprimir contraseñas, llaves ni contenido de archivos de entorno.

#### Scenario: Ejecución repetida
- **WHEN** se ejecuta el script dos veces seguidas
- **THEN** el estado del servidor es idéntico y la salida no contiene secretos

### Requirement: Requisitos comprobados
El script MUST reportar, con un resultado de cumple, advertencia o falla por cada punto: sistema
operativo, PHP y su versión mínima (8.3), extensiones `pdo_mysql`, `mbstring`, `intl` y
`bcmath`, estado del servicio PHP-FPM, versión de Nginx y validez de su configuración, versión
y escucha de MariaDB, memoria y swap disponibles, espacio en disco, `disable_functions`,
`max_connections` frente al uso actual, y la presencia de caché FastCGI a nivel global.

#### Scenario: Servidor conforme
- **WHEN** el servidor cumple todos los requisitos
- **THEN** el resumen final indica cero fallas

#### Scenario: Extensión ausente
- **WHEN** falta una extensión requerida
- **THEN** ese punto se marca como falla y el código de salida es distinto de cero

### Requirement: Disponibilidad para la aplicación
El script MUST indicar si ya existen la base y el usuario de Flow Balance, el pool dedicado, el
usuario de despliegue y el vhost, sin crearlos si no existen.

#### Scenario: Recursos pendientes
- **WHEN** aún no existen el pool ni la base de Flow Balance
- **THEN** se informan como pendientes de crear y no como errores del servidor
