# Refinamiento de UX/UI

Registro del cambio de OpenSpec `refine-app-ui-ux` (`openspec/changes/refine-app-ui-ux/`).

## Entorno de validación

- PostgreSQL: contenedor `flow-balance-validation-built-postgres-1` en `127.0.0.1:54329`, con las bases `flow_balance` y `flow_balance_test`.
- API aislada: `APP_ENV=testing herd php -S 127.0.0.1:8081 -t public`, que usa `flow_balance_test`. El `.env` no se modificó.
- Astro dev en `127.0.0.1:4321` con `APP_ENV=testing` y `API_PROXY_TARGET=http://127.0.0.1:8081`, detrás del proxy HTTPS de Herd (`https://flow-balance.test`).
- Playwright: `NODE_EXTRA_CA_CERTS` apunta a la CA de Herd (`LaravelValetCASelfSigned.pem`) porque el cliente de peticiones de Node no confía en ella por defecto. PHPUnit necesita las variables `TEST_DB_*` exportadas desde `.env`; sin ellas omite 8 pruebas en silencio.
- Barra de herramientas de Astro dev desactivada con `astro preferences disable devToolbar --project`. En móvil tapa la navegación inferior y hace fallar `app-usability.spec.ts`.
- `astro preview` no sirve detrás de Herd: responde 403 («This host is not allowed») porque `allowedHosts` de `vite.preview` no se aplica en el preview de Astro 5. Pendiente fuera de este cambio.

## Línea base (antes de los cambios de interfaz)

| Suite | Resultado |
| --- | --- |
| `astro build` | 14 páginas compiladas |
| PHPUnit | 25 pruebas, 501 aserciones |
| Playwright con `--workers=1` | 13 de 13 |
| Playwright en paralelo | 9 a 12 de 13; los fallos cambian entre corridas (carrera de hidratación en el servidor dev) |

Las capturas de referencia están en `docs/validation/screens/before/` (17 pantallas, 2 tamaños, 2 temas). Se regeneran con
`CAPTURE_DIR=$PWD/docs/validation/screens/before npm --prefix frontend run test:e2e -- tests/capture-screens.spec.ts --workers=1`.

## Hallazgos de las capturas (tarea 1.5)

Comparación con `specs/006-improve-app-usability/references/` (tablero móvil y escritorio, tema claro y oscuro):

1. **Estado vacío visible con datos.** En «Últimos cobros» el tablero muestra «Aún no tienes cobros.» debajo de cuatro cobros, a 360 y 1080 px.
2. **Chip «Pendiente» sin tema.** En tema claro es un fondo oscuro con texto ámbar, fuera de la paleta clara.
3. **Selector de tema sin estilo.** En la barra inferior móvil y en la lateral es un `select` nativo con borde negro, distinto al resto de controles.
4. **Búsqueda del tablero.** El ícono se ve como un punto negro y el texto del campo se corta («Buscar clientes o p…») a 360 px.
5. **Tarjetas de indicadores.** En escritorio las tres tarjetas superiores tienen peso desigual: el abonado envuelve «MXN» y la cifra en dos líneas y «Clientes activos» queda casi vacía.
6. **Navegación en tema claro.** La barra lateral usa el acento sólido y contrasta bien, pero rompe con el resto de superficies claras de la referencia.
7. **Barra inferior en capturas de página completa.** Aparece a mitad de página porque es `position: fixed`; es un artefacto de la captura y no un defecto.

## Contraste (tarea 1.6)

Medido con la fórmula WCAG 2.x sobre los tokens de `theme.css`.

| Par | Oscuro | Claro | Mínimo | Resultado |
| --- | --- | --- | --- | --- |
| Texto sobre superficie | 16.99 | 14.59 | 4.5 | Cumple |
| Texto secundario sobre superficie | 5.76 | 6.72 | 4.5 | Cumple |
| Enlace sobre superficie | 10.11 | 9.88 | 4.5 | Cumple |
| Peligro sobre superficie | 8.16 | 7.50 | 4.5 | Cumple |
| Acento (foco) sobre superficie | 4.41 | 9.08 | 3 | Cumple |
| Navegación clara: blanco sobre acento / activo | — | 9.08 / 5.98 | 4.5 | Cumple |
| **Placeholder sobre campo** | **3.96** | **4.39** | 4.5 | **Falla** |
| **Borde de campo sobre fondo** | **1.57** | **1.81** | 3 (componente de UI) | **Falla** |

Pendiente de la tarea 2.4: subir el contraste de `--placeholder` y de `--input-border` en ambos temas. El borde del campo es el que define dónde está el control, por lo que el criterio de 3:1 aplica.

## Avance de los grupos 1 a 3

- **Capas CSS.** `theme.css` pasó a ser el punto de entrada con `@layer tokens, base, components, screens` e importa `tokens.css`, `base.css`, `components.css` y `screens.css`. La reestructuración pura no cambió ningún píxel: las 7 capturas distintas lo son también entre dos corridas del mismo código (animaciones y degradados).
- **Tokens nuevos.** Radios (`--radius-sm|md|lg|pill`), alturas de control, espaciado, movimiento, superposición, `--on-accent`, anillo de foco por tema, `--field-border` y colores de chip (`--warn-*`, `--ok-*`). No quedan colores ni radios literales equivalentes fuera de `tokens.css`, salvo formas propias de la landing.
- **Contraste corregido.** Placeholder 5.04 (oscuro) y 4.86 (claro). Borde de campo 3.69 y 3.49 sobre el fondo del campo. Todos cumplen.
- **Defectos corregidos.** `color-scheme` por tema (el selector del tablero ya no se ve oscuro en claro). El chip «Pendiente» usa tokens y es legible en ambos temas. `cursor: wait` solo con `aria-busy`. El selector de tema comparte el estilo de los demás controles.
- **Primitivas.** `.btn` (+ `-primary`, `-danger`, `-icon`), `.field`, `select`, `textarea`, `.card`, `.metric`, `.chip` (`-warn`, `-ok`), `.dialog`, `.state` (`-loading`, `-empty`, `-error`) y `StateMessage.astro`. `.button`, `.income-panel`, `.income-metrics > div` y `.income-status` quedan como alias hasta el grupo 10. Los diálogos del mes, de captura, de descarte y de eliminación comparten `.dialog`. `AccountCollection.astro` usa `.state` para carga, vacío y error.
- **Guía de estilos.** `/styleguide` existe solo en el servidor de desarrollo; en producción responde 404 y no se genera. Se captura en el recorrido de `capture-screens.spec.ts`.
- **Botones.** Todos miden al menos 44 px. Los filtros de periodo del historial quedan más altos que antes.
- **Botón ocupado.** `[aria-busy="true"]` dibuja un indicador con `currentColor` y respeta `--duration-slow` (0 con movimiento reducido).
- **Estados de listado.** `frontend/tests/list-states.spec.ts` comprueba el vacío de Clientes y el error de Proyectos con la carga abortada, el reintento y la recuperación. El cambio de `role` de `status` a `alert` sobre un nodo existente no se comprobó con lector de pantalla; queda como verificación manual.
- **Pruebas.** Astro build correcto, Playwright 14 de 14 con `--workers=1` (13 previas más la de estados) y la de capturas omitida sin `CAPTURE_DIR`.
- **Capturas.** Una corrida de capturas se interrumpió una vez sin causa reproducible y pasó al repetirla en 48 s.

### Pendiente detectado para grupos posteriores

- «Aún no tienes cobros.» aparece en el tablero junto a cobros existentes (`.collections .empty:not([hidden])` anula `.records:not(:empty) + .empty`). Tarea 6.3.
- El ícono de búsqueda del tablero se ve como un punto y el placeholder se corta a 360 px. Tarea 6.1.

## Resultado final (grupos 4 a 10)

### Hallazgos de la tarea 1.5 tras los cambios

| # | Hallazgo | Estado |
| --- | --- | --- |
| 1 | «Aún no tienes cobros.» junto a cobros existentes | Corregido; `dashboard-states.spec.ts` lo cubre |
| 2 | Chip «Pendiente» sin tema | Corregido con `--warn-*` y `--ok-*` |
| 3 | Selector de tema nativo | Corregido; comparte el estilo de todos los selectores |
| 4 | Ícono y placeholder de la búsqueda del tablero | Corregido; el campo ocupa su fila a 360 px |
| 5 | Tarjetas de indicadores desiguales en escritorio | Mejorado: la tarjeta de abonado pesa 1.5 y las cifras escalan |
| 6 | Navegación clara en acento sólido | Sustituida por superficie con borde de acento en la sección activa, en ambos temas y tamaños |

Diferencias frente a las referencias de 006 que se mantienen: la navegación lateral de escritorio usa superficie y no acento sólido en tema claro, y los importes de los detalles se muestran sin separador de miles (`MXN 22000.00`), mientras el tablero sí lo usa (`MXN 22,000.00`). Unificar el formato cambia textos que las pruebas esperan, por lo que queda como propuesta aparte.

### Contraste final

| Par | Oscuro | Claro | Mínimo |
| --- | --- | --- | --- |
| Placeholder sobre campo | 5.04 | 4.86 | 4.5 |
| Borde de campo sobre fondo | 3.69 | 3.49 | 3 |
| Barras del gráfico sobre superficie | 3.01 | 3.64 | 3 |
| Chip pendiente | 9.65 | 6.64 | 4.5 |
| Chip liquidado | 9.98 | 7.12 | 4.5 |
| Navegación, sección activa | 9.10 | 8.29 | 4.5 |
| Navegación, estado normal | 14.74 | 10.38 | 4.5 |
| Botón primario, texto blanco | 4.7 como mínimo (antes 4.23 y 3.67) | 9.08 | 4.5 |

**Resuelto.** El degradado de #8B5CF6 a #F43F5E dejaba el texto blanco en 4.23:1 y 3.67:1. El botón primario oscuro usa ahora `--gradient-action` (#7C3AED a #E11D48): 5.7:1 en el extremo violeta, 4.7:1 en el rosa y mínimo 4.7:1 a lo largo del degradado (muestreado cada 1 %). `--gradient` se conserva en barras de progreso y adornos sin texto.

### Pruebas

- `astro build`: 15 páginas contadas; `/styleguide` no se genera en producción.
- PHPUnit con `TEST_DB_*` y `APP_ENV=testing`: 25 pruebas, 501 aserciones. Incluye la prueba de migración con reversión y reaplicación.
- Playwright: 19 de 19 (más la de capturas, omitida sin `CAPTURE_DIR`), con `--workers=1` y también en paralelo en dos corridas seguidas. Nuevas: `app-shell`, `list-states` (2), `dashboard-states`, `form-errors` y `control-size`, que barre botones, selectores y campos visibles y exige 44 px como mínimo a 360 y 1080 px. Cambió un selector de clase en `project-delivery.spec.ts` (`.income-metric-primary` a `.metric-primary`); ningún nombre accesible cambió.
- Capturas finales en `docs/validation/screens/after/` (80 imágenes, no versionadas). El renombrado de clases del grupo 10 no cambió ningún píxel.

### Revisión constitucional

Sin cambios de API, esquema ni reglas monetarias; no se tocó `src/` ni `db/`. Identificadores y comentarios en inglés, interfaz es-MX. Los listados y formularios conservan CSRF, pertenencia y política sin caché porque no cambió ninguna llamada. Los estados vacío y de error no exponen datos privados.

### Pendientes físicos

- Reabrir la PWA instalada en un teléfono y comprobar que conserva el tema.
- Repetir los recorridos al 200 % de zoom en un dispositivo real. Se verificó el equivalente a 180 px de ancho en `app-shell.spec.ts`.
- Comprobar con un lector de pantalla que el cambio de `status` a `alert` en los estados se anuncia.

### Cumplimiento de los requisitos

- **Cumplido tras el ajuste:** el botón primario oscuro usa `--gradient-action` (mínimo 4.7:1 con texto blanco).
- **Colores literales:** solo quedan los de `tokens.css`, los cuatro colores de íconos de la landing en `index.astro` y los `theme-color` del layout, que son valores para el navegador.
