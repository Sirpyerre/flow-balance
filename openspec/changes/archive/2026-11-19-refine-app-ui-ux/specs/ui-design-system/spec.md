## Purpose

Definir el sistema visual compartido de Flow Balance: tokens y primitivas de control que todas las pantallas reutilizan, con paridad entre tema claro y oscuro.

## ADDED Requirements

### Requirement: Tokens semánticos únicos
La hoja de estilos MUST declarar los tokens de color, radio, altura de control, espaciado, sombra y movimiento en un solo sistema con valores por tema. Los componentes MUST consumir tokens y MUST NOT escribir colores ni radios literales cuando exista un token equivalente.

#### Scenario: Cambio de tema sin residuos
- **WHEN** el usuario cambia de tema en cualquier pantalla
- **THEN** todos los fondos, textos, bordes, controles nativos y diálogos adoptan la paleta del tema elegido
- **AND** ningún elemento conserva colores del tema anterior

#### Scenario: Controles nativos
- **WHEN** se muestra un selector, campo de fecha o casilla en tema claro
- **THEN** el control nativo se presenta con esquema claro

### Requirement: Primitivas de control consistentes
Los botones, campos, selectores y áreas de texto MUST compartir altura, radio, foco y estados en toda la app, sin depender de clases de una pantalla concreta. La altura mínima táctil MUST ser de 44 px.

#### Scenario: Selector fuera de un panel de ingresos
- **WHEN** un selector aparece en el tablero, un diálogo o la navegación
- **THEN** luce igual que el de un formulario de cobro

#### Scenario: Botón en operación
- **WHEN** una acción está en curso
- **THEN** el botón correspondiente muestra el indicador de progreso y queda desactivado
- **AND** los demás botones desactivados no muestran cursor de espera

### Requirement: Jerarquía de acciones
Cada pantalla MUST presentar como máximo una acción primaria visible por contexto, con las secundarias en contorno y las destructivas diferenciadas por color y etiqueta, sin depender solo del color.

#### Scenario: Formulario de cobro
- **WHEN** el usuario abre el registro de un cobro
- **THEN** «Guardar cobro» es la única acción primaria y «Cancelar» es secundaria

### Requirement: Estados visibles
Las vistas que cargan datos MUST mostrar estados de carga, vacío y error con un patrón común y un mensaje recuperable. Las etiquetas de estado MUST incluir texto además del color.

#### Scenario: Cuenta sin datos
- **WHEN** una cuenta no tiene clientes, proyectos o cobros
- **THEN** el listado muestra un estado vacío con una acción para crear el primer registro

#### Scenario: Fallo de red
- **WHEN** falla la carga de un listado
- **THEN** se muestra un mensaje de error con opción de reintentar y sin exponer datos privados

### Requirement: Accesibilidad visual
El foco MUST ser visible con contraste suficiente en ambos temas. El texto y los controles MUST cumplir contraste WCAG AA. Las animaciones MUST respetar la preferencia de movimiento reducido.

#### Scenario: Botón primario en tema oscuro
- **WHEN** se muestra el botón primario con texto blanco en tema oscuro
- **THEN** el contraste del texto es de al menos 4.5:1 en todo el degradado de fondo

#### Scenario: Importes con separador de miles
- **WHEN** se muestra un importe de 1000 o más en cualquier pantalla
- **THEN** la parte entera agrupa miles con coma y conserva los decimales recibidos, por ejemplo `MXN 22,000.00`

#### Scenario: Navegación con teclado
- **WHEN** el usuario recorre una pantalla con Tab
- **THEN** cada control muestra un anillo de foco visible sobre su fondo en ambos temas

#### Scenario: Movimiento reducido
- **WHEN** el sistema pide movimiento reducido
- **THEN** transiciones y animaciones se omiten o se reducen a un cambio instantáneo
