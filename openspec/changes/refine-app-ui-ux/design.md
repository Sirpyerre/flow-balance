## Context

Ver `proposal.md` para la motivación. Estado observado en el código:

- `frontend/src/styles/theme.css` (394 líneas) declara `:root` dos veces: la paleta base al inicio y `--danger`, `--highlight-surface` y `--highlight-end` hacia la línea 277. El tema claro vive en `:root[data-theme="light"]` y fuerza `--gradient` a un color sólido.
- Alturas de control: campos de 48 px (`input`), 44 px en botones de formulario, `2.75rem` en `.payment-entry-form`, 38 px en `#balance-filter` y 2.625 rem en el selector de mes.
- Radios mezclados: 10 px, 16 px, `.325rem`, `.375rem`, `.625rem`, `.65rem`, `.75rem`, 1 rem.
- Colores literales: `white`, `#fff6`, `#fff`, `#0009` y `rgb(0 0 0 / 65%)` en fondos de diálogo.
- `button:disabled { cursor: wait }` afecta a todo botón desactivado, no solo a los que están en operación.
- `select` y `textarea` solo se estilizan dentro de `.income-panel`. `dashboard.astro` repite estilos propios de select y fija `color-scheme: dark`, lo que rompe los controles nativos en tema claro.
- Clases `income-*` se usan como primitivas de toda la app (tarjetas, métricas, estado).
- `dashboard.astro` (768 líneas), `index.astro` y `SiteHeader.astro` tienen `<style>` propios.
- En tema claro, la navegación de escritorio es blanca sobre el acento (`theme.css`, bloque `@media (min-width: 701px)` final) y en móvil usa superficie con texto de etiqueta. El contraste de ambos casos no está verificado.
- Las pruebas de Playwright seleccionan por rol y nombre (`getByRole`, `getByLabel`) y unos pocos ids (`#history-total`). Cambiar clases es seguro; cambiar etiquetas no.

## Goals / Non-Goals

**Goals:**
- Un único sistema de tokens y primitivas, adoptado por todas las pantallas en este cambio.
- Paridad visual y de contraste entre tema claro y oscuro.
- Adopción incremental: cada grupo de tareas deja la app funcional y con sus pruebas en verde.

**Non-Goals:**
- Rediseñar la identidad (oscuro por defecto, violeta a rosa, Inter, tarjetas de 16 px).
- Introducir Tailwind, una librería de componentes o un preprocesador.
- Cambiar API, esquema, reglas monetarias o textos funcionales.
- Reescribir componentes React o páginas Astro más allá de su marcado y clases.

## Decisions

1. **CSS con tokens y capas, sin dependencias nuevas.** Se reorganiza `theme.css` con `@layer tokens, base, components, screens`, en archivos importados desde el layout. Alternativa descartada: Tailwind o CSS Modules, porque costaría reescribir unas 2 300 líneas de marcado y no resuelve la inconsistencia de valores. Las capas permiten migrar pantalla por pantalla sin pelear con la especificidad.
2. **Escala fija de tokens.** Radios 8, 10, 16 px y pastilla. Controles de 44 px (botones) y 48 px (campos de formulario). Espaciado en pasos de 4 px. Las superficies elevadas usan borde y no sombra, para conservar el aspecto actual.
3. **Primitivas con nombres genéricos y alias temporales.** Se crean `.btn`, `.field`, `.select`, `.card`, `.metric`, `.chip`, `.state` y `.dialog`. Los `income-*` y `workspace-*` quedan como alias hasta migrar su última pantalla y se retiran en el grupo final, para no romper pruebas ni hacer un cambio masivo.
4. **`color-scheme` por tema.** Se declara en `:root` y en `:root[data-theme="light"]` y se eliminan las declaraciones locales.
5. **Cursor de espera solo en `[aria-busy="true"]`.** Los botones desactivados por validación usan `not-allowed`.
6. **Degradado solo en la acción primaria del tema oscuro.** En claro se mantiene el acento sólido ya definido; se documenta como decisión.
7. **Navegación en claro con contraste medido.** Se mide antes de elegir entre acento sólido o superficie clara con indicador activo. Se prefiere superficie con indicador si el blanco sobre `#4815d2` falla en foco o en texto pequeño.
   *Resuelta al implementar:* el blanco sobre el acento cumplía (9.08:1), pero se eligió superficie con borde de acento en la sección activa, en ambos temas y tamaños, para coherencia con las referencias y para quitar el caso especial del tema claro.
8. **Los bloques `<style>` de página conservan solo lo específico de la pantalla** (gráfica, rejilla del tablero). Selectores, tarjetas y diálogos pasan al sistema.
9. **Verificación visual con Playwright.** Un recorrido genera capturas a 360 y 1080 px en ambos temas. No hay comparación por píxel automática, porque datos y fuentes varían; la comparación con las referencias de 006 es manual y queda documentada.

## Risks / Trade-offs

- [Regresión en pruebas por cambios de marcado] → Conservar roles, etiquetas e ids; correr la suite completa de Playwright tras cada grupo.
- [Alias `income-*` que nunca se retiran] → Tarea final que busca usos restantes.
- [Superficie de cambio grande en `dashboard.astro`] → Migrarlo en su propio grupo, con captura antes y después.
- [El tema claro no cumple AA con el acento actual] → Medir primero; ajustar el token, no el componente.
- [Soporte de `@layer` en la PWA] → Soporte amplio desde 2022; comprobar en los navegadores objetivo.
- [Pendientes físicos] → La reapertura de la PWA en teléfono y el zoom al 200 % no son automatizables y se listan en la validación.

## Migration Plan

Entrega por grupos de tareas, cada uno desplegable por separado. No hay migración de datos ni cambio de API. Para revertir se revierte el commit del grupo; los alias evitan estados intermedios rotos.

## Open Questions

- ¿Se conserva el botón primario sólido en el tema claro? La decisión 6 asume que sí. Las capturas finales se ven coherentes.
- El degradado del botón primario oscuro queda bajo 4.5:1 con texto blanco (4.23 y 3.67). Requiere una decisión de diseño sobre la identidad y no se cambió.
- ¿Se quieren ilustraciones propias en los estados vacíos? El diseño asume un ícono lineal simple, sin recursos nuevos.
