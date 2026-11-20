## Why

El incremento 006 entregó navegación, temas y flujos nuevos, pero la interfaz creció por capas: los tokens están en dos bloques de `theme.css`, cada pantalla define sus propias alturas, radios y colores, y componentes genéricos usan nombres `income-*`. Antes de seguir agregando funciones conviene unificar el sistema visual y aplicar criterios de UX/UI a todas las pantallas, para que la app se sienta coherente en escritorio, móvil, tema claro y oscuro.

## What Changes

- Consolidar los tokens de `theme.css` en un solo sistema: color, espaciado, radios, alturas de control, sombras, movimiento y estados. Sustituir colores y radios escritos a mano por tokens.
- Crear primitivas compartidas con clases genéricas: botón (primario, secundario, peligro, cargando), campo, selector, área de texto, tarjeta, chip de estado, métrica, diálogo y estados de carga, vacío y error.
- Corregir defectos de tema detectados en la auditoría, como `color-scheme: dark` fijo en `dashboard.astro` y selectores sin estilo fuera de `.income-panel`.
- Rediseñar el shell privado (navegación lateral e inferior, encabezado de página, selector de tema) con jerarquía, estados y contraste revisados en ambos temas.
- Aplicar un patrón de pantalla a cada familia: público (landing, registro, acceso), tablero, listados, formularios y detalles. Cada una con estados de carga, vacío y error uniformes.
- Mover los bloques `<style>` de `dashboard.astro`, `index.astro` y `SiteHeader.astro` a capas del sistema cuando usen valores que ya existen como token.
- Establecer una línea base visual a 360 y 1080 px en ambos temas y compararla contra las referencias de `specs/006-improve-app-usability/references/`.
- Pasar la gobernanza del proyecto a OpenSpec: enmendar el principio I de la constitución y apuntar `AGENTS.md` a `openspec/`.

Cambios incorporados durante la aplicación, a petición del producto:

- El selector de tema pasa a un interruptor (`role="switch"`, nombre «Modo oscuro») con perilla de sol y luna.
- «Registrar cobro» y «Editar cobro» usan dos columnas desde 901 px, con el resumen de pagos al lado.
- Los listados de clientes y proyectos y el panel «Clientes registrados» muestran la fecha de creación. Para eso `GET /api/dashboard` agrega el campo `createdOn` (`AAAA-MM-DD`, zona de México) a cada cliente y proyecto. Es la única excepción a «sin backend»: es aditiva, de solo lectura y no toca el esquema, que ya tenía `created_at`.
- Los importes del frontend agrupan miles con coma (`MXN 22,000.00`) en todas las pantallas, con la agrupación hecha sobre la cadena decimal para no pasar por coma flotante.
- El botón primario oscuro usa `--gradient-action` (`#7C3AED → #E11D48`), que mantiene 4.5:1 con texto blanco en todo el degradado.

Fuera de alcance: rutas de API, esquema de datos, reglas monetarias, copy funcional y nombres accesibles existentes, salvo el campo `createdOn` indicado arriba. Ninguna otra tarea los cambia.

## Capabilities

### New Capabilities

- `ui-design-system`: tokens, primitivas de control y estados compartidos, con paridad entre tema claro y oscuro.
- `app-shell`: navegación privada, encabezado de página, cambio de tema y comportamiento de área segura.
- `screen-patterns`: composición de pantallas públicas, tablero, listados, formularios y detalles, incluidos estados de carga, vacío y error.

### Modified Capabilities

Ninguna. `openspec/specs/` está vacío; los requisitos de 001 a 006 siguen en `specs/` y no se migran en este cambio.

## Impact

- Código: `frontend/src/styles/theme.css`, `frontend/src/layouts/AppLayout.astro`, componentes de `frontend/src/components/`, páginas de `frontend/src/pages/`.
- Pruebas: los 13 recorridos de Playwright deben seguir pasando sin cambiar sus selectores; se agregan comprobaciones de tokens y de captura visual.
- Documentación: `docs/validation/` recibe la línea base; `.specify/memory/constitution.md` y `AGENTS.md` cambian de referencia.
- Sin dependencias nuevas. Backend: solo el campo `createdOn` en `PostgresAccountDataReader` y una aserción en `AccountAccessTest`.
- Relación con 006: las tareas T031 y T032 siguen abiertas. Este cambio absorbe T031 (comparar referencias) como línea base y deja T032 para el cierre de 006.
