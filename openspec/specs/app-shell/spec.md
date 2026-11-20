# app-shell Specification

## Purpose
Definir el marco de las pantallas privadas: navegación, encabezado de página y cambio de tema, de modo que el usuario siempre sepa dónde está y cómo avanzar.

## Requirements

### Requirement: Navegación adaptativa
Las pantallas privadas MUST mostrar navegación lateral en escritorio y barra inferior a 700 px o menos, con etiquetas visibles y la sección activa marcada con `aria-current` y un indicador visual. La barra inferior MUST respetar el área segura y MUST NOT cubrir el contenido.

#### Scenario: Sección activa
- **WHEN** el usuario está en el detalle de un proyecto
- **THEN** «Proyectos» aparece como sección activa

#### Scenario: Móvil con área segura
- **WHEN** la pantalla mide 360 px de ancho y tiene área segura inferior
- **THEN** el último elemento de la pantalla es alcanzable por encima de la barra

### Requirement: Contraste de la navegación en ambos temas
Los elementos de la navegación MUST cumplir contraste AA en estado normal, activo y de foco, en tema claro y oscuro, en escritorio y móvil.

#### Scenario: Tema claro en escritorio
- **WHEN** se muestra la navegación lateral en tema claro
- **THEN** texto e íconos tienen contraste AA contra su fondo

### Requirement: Encabezado de página uniforme
Cada pantalla privada MUST iniciar con un encabezado con título, contexto secundario opcional y acción principal alineada en un lugar predecible, que se reorganiza en una columna a 360 px.

#### Scenario: Listado con alta
- **WHEN** el usuario abre Clientes
- **THEN** ve el título, el conteo y la acción «Nuevo cliente» en el encabezado

### Requirement: Cambio de tema accesible
El control de tema MUST tener nombre accesible y estado, estar disponible en la landing y en las pantallas privadas, y conservar la preferencia sin mostrar el tema equivocado en el primer renderizado.

#### Scenario: Interruptor
- **WHEN** el usuario activa el control de tema
- **THEN** el control es un interruptor con rol `switch`, nombre «Modo oscuro» y `aria-checked`, mide al menos 44 px de alto y muestra un sol o una luna en la perilla según el tema

#### Scenario: Recarga
- **WHEN** el usuario recarga después de elegir tema claro
- **THEN** la página se muestra en claro desde el primer renderizado
