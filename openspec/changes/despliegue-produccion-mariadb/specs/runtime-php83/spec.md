## Purpose

Permite que la aplicación se instale y corra en PHP 8.3, la versión del servidor de producción,
sin perder compatibilidad con PHP 8.4 usado en desarrollo.

## ADDED Requirements

### Requirement: Versiones de PHP soportadas
La aplicación MUST declarar compatibilidad con PHP 8.3 y 8.4 y MUST requerir `ext-pdo_mysql` en
lugar de `ext-pdo_pgsql`. No MUST usar sintaxis ni funciones exclusivas de PHP 8.4.

#### Scenario: Instalación en 8.3
- **WHEN** se instalan las dependencias de producción con PHP 8.3
- **THEN** la instalación termina sin errores de plataforma

#### Scenario: Desarrollo en 8.4
- **WHEN** se instalan las dependencias con PHP 8.4
- **THEN** la instalación y la suite de pruebas funcionan igual

### Requirement: Lock resoluble para la plataforma de producción
El archivo de bloqueo de dependencias MUST resolverse contra PHP 8.3, de modo que ningún
paquete instalado exija una versión superior.

#### Scenario: Verificación de plataforma
- **WHEN** se ejecuta la verificación de requisitos de plataforma con PHP 8.3
- **THEN** no se reporta ningún paquete incompatible

### Requirement: Pruebas en la versión de producción
La integración continua MUST ejecutar la suite de PHPUnit con PHP 8.3.

#### Scenario: Pull request
- **WHEN** se abre un pull request
- **THEN** la suite corre en PHP 8.3 contra MariaDB 10.11 y su resultado condiciona el despliegue
