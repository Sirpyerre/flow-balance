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

Fuera de alcance: rutas de API, esquema de datos, reglas monetarias, copy funcional y nombres accesibles existentes. Ninguna tarea los cambia.

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
- Sin dependencias nuevas y sin cambios en backend.
- Relación con 006: las tareas T031 y T032 siguen abiertas. Este cambio absorbe T031 (comparar referencias) como línea base y deja T032 para el cierre de 006.
