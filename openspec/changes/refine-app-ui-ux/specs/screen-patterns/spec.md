## Purpose

Definir cómo se componen las familias de pantallas de Flow Balance para que cada una siga un patrón reconocible, en escritorio y móvil.

## ADDED Requirements

### Requirement: Pantallas públicas coherentes
La landing, el registro y el acceso MUST compartir encabezado, tipografía, tarjeta y estados de campo con las pantallas privadas, y MUST conservar la identidad visual vigente.

#### Scenario: Error de acceso
- **WHEN** el usuario envía credenciales inválidas
- **THEN** el mensaje aparece bajo el campo o en la tarjeta con rol de alerta y el foco queda en un lugar recuperable

### Requirement: Tablero legible por prioridad
El tablero MUST ordenar su contenido por prioridad: abonado del periodo con la acción «Registrar cobro», indicadores secundarios, gráfica, saldos por proyecto y últimos cobros. En escritorio MAY usar varias columnas; a 360 px MUST usar una.

#### Scenario: Móvil
- **WHEN** el tablero se abre a 360 px
- **THEN** el abonado y «Registrar cobro» aparecen primero y no hay desplazamiento horizontal

### Requirement: Listados con patrón común
Los listados de cobros, proyectos y clientes MUST mostrar encabezado con acción de alta, filas con dato principal, dato secundario y enlace de detalle, orden estable y estados de carga, vacío y error. A 360 px las filas MUST ser tarjetas o filas de dos columnas sin desbordar.

#### Scenario: Nombre largo
- **WHEN** un cliente o proyecto tiene un nombre muy largo
- **THEN** el texto se ajusta sin desbordar y las acciones siguen visibles

### Requirement: Formularios en una estructura estable
Los formularios MUST agrupar campos por tema, mostrar etiquetas persistentes, errores junto al campo con resumen accesible, y barra de acciones con primaria y secundaria. Con panel de apoyo, MUST usar dos columnas en escritorio y una a 360 px con el resumen visible antes de guardar.

#### Scenario: Validación
- **WHEN** el usuario envía un formulario con errores
- **THEN** cada campo inválido muestra su mensaje y `aria-invalid`
- **AND** el foco se mueve al primer campo inválido

#### Scenario: Etiquetas existentes
- **WHEN** se rediseña un formulario
- **THEN** las etiquetas y nombres accesibles de campos y botones se conservan sin cambios

### Requirement: Detalles con tarjetas de datos
Las pantallas de detalle MUST presentar los importes en tarjetas de métricas, con el dato principal destacado, acciones diferenciadas por jerarquía y los importes con cifras tabulares y moneda visible.

#### Scenario: Detalle de cobro
- **WHEN** el usuario abre un cobro
- **THEN** ve tarjetas separadas de abonado, comisión, impuestos y recibido
- **AND** «Editar cobro» y «Eliminar cobro» se distinguen por estilo y etiqueta

### Requirement: Línea base visual verificable
Cada familia de pantallas MUST poder capturarse a 360 y 1080 px en tema claro y oscuro mediante un recorrido automatizado y repetible, y las diferencias frente a las referencias de 006 MUST registrarse en `docs/validation/`. Las capturas son productos regenerables y NO MUST versionarse.

#### Scenario: Regenerar las capturas
- **WHEN** se ejecuta `capture-screens.spec.ts` con `CAPTURE_DIR` apuntando a una carpeta
- **THEN** esa carpeta contiene una captura por pantalla, tamaño y tema
- **AND** la comparación con las referencias está documentada en `docs/validation/ui-refinement.md`
