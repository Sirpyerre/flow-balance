## 1. Gobernanza y línea base

- [x] 1.1 Enmendar el principio I de `.specify/memory/constitution.md` para que OpenSpec sustituya a Codex y Spec Kit, conservando el requisito de documentos en español; verificar que el encabezado de versión y el informe de sincronización quedan actualizados.
- [x] 1.2 Cambiar `AGENTS.md` para apuntar a `openspec/` y a este cambio en lugar de `specs/006-improve-app-usability/plan.md`; verificar que el archivo resultante no menciona rutas inexistentes.
- [x] 1.3 Ejecutar Astro build, PHPUnit y Playwright con la base aislada y anotar la línea base en `docs/validation/ui-refinement.md`; verificar que el documento lista resultados y fallos previos.
- [x] 1.4 Capturar las pantallas actuales a 360 y 1080 px en tema claro y oscuro en `docs/validation/screens/before/` con un recorrido de Playwright; verificar 4 capturas por pantalla clave.
- [x] 1.5 Comparar las nueve referencias de `specs/006-improve-app-usability/references/` con las capturas y registrar diferencias en `docs/validation/ui-refinement.md` (absorbe T031 de 006); verificar que cada referencia tiene una nota.
- [x] 1.6 Medir el contraste AA de texto, enlaces, navegación y foco en ambos temas y listar las fallas en `docs/validation/ui-refinement.md`; verificar con el script de contraste del sistema.

## 2. Tokens y base

- [x] 2.1 Reorganizar `frontend/src/styles/theme.css` en capas `tokens`, `base`, `components`, `screens` y fusionar los dos bloques `:root`; verificar que `astro build` termina y que ninguna regla pierde efecto en las capturas de 1.4.
- [x] 2.2 Definir escalas de radio, altura de control, espaciado, movimiento y superposición de diálogo como tokens, y reemplazar los literales `white`, `#fff6`, `#fff`, `#0009` y `rgb(0 0 0 / 65%)`; verificar con `grep` que no quedan literales equivalentes.
- [x] 2.3 Declarar `color-scheme` por tema y quitar `color-scheme: dark` de `frontend/src/pages/dashboard.astro`; verificar que el selector de balance y el campo de fecha se ven claros en tema claro.
- [x] 2.4 Ajustar los tokens que fallen contraste según 1.6; verificar que el script de contraste no reporta fallas AA.
- [x] 2.5 Limitar `cursor: wait` a `[aria-busy="true"]` y marcar los botones en operación en `IncomeSetupForm.tsx`, `PaymentForm.tsx`, `PaymentCorrections.tsx`, `PaymentChargeForm.tsx`, `LoginForm.tsx` y `RegisterForm.tsx`; verificar que las pruebas de doble guardado siguen pasando.

## 3. Primitivas compartidas

- [x] 3.1 Crear `.btn` (primario, secundario, peligro, cargando, ícono) con altura mínima de 44 px y estados de foco y desactivado; verificar en una página de muestra en ambos temas.
- [x] 3.2 Crear `.field`, `.select`, `.textarea` y estados de error con `aria-invalid` fuera de `.income-panel`; verificar que `ThemeSelector` y el selector del tablero usan el mismo estilo.
- [x] 3.3 Crear `.card`, `.metric` y `.chip` de estado con texto además de color, y `.dialog` con superposición tokenizada; verificar que `MonthPicker`, `DiscardChangesDialog` y el diálogo de captura los comparten.
- [x] 3.4 Crear el patrón `.state` de carga, vacío y error con acción, y un componente `StateMessage` reutilizable; verificar que un listado vacío y uno con error lo muestran.
- [x] 3.5 Añadir alias que mapeen `income-*` y `workspace-*` a las primitivas; verificar que la suite de Playwright completa pasa sin cambios.

## 4. Shell privado

- [x] 4.1 Rediseñar `PrivateNavigation.astro` con indicador de sección activa, etiquetas visibles y foco, según la decisión 7; verificar contraste AA en claro y oscuro, escritorio y móvil.
- [x] 4.2 Crear un componente de encabezado de página (título, contexto, acción) y usarlo desde `AppLayout.astro` o las páginas privadas; verificar que a 360 px se reorganiza en una columna.
- [x] 4.3 Revisar el espacio inferior y el área segura a 360 px y 200 % de zoom; verificar que el último elemento de cada pantalla queda por encima de la barra.
- [x] 4.4 Verificar el cambio de tema y la ausencia de parpadeo en el primer renderizado con las pruebas de tema de `app-usability.spec.ts`.

## 5. Pantallas públicas

- [x] 5.1 Migrar `index.astro` y `SiteHeader.astro` a tokens y primitivas, conservando la composición y la animación de entrada con movimiento reducido; verificar landing a 360 y 1080 px en ambos temas.
- [x] 5.2 Migrar `LoginForm.tsx`, `RegisterForm.tsx` y `AuthField.tsx` a `.field` y `.btn`, con foco en el primer campo inválido; verificar `mobile-auth.spec.ts` y las capturas.

## 6. Tablero

- [x] 6.1 Mover a primitivas los estilos de tarjeta, selector, diálogo y estado de `frontend/src/pages/dashboard.astro` y dejar en su bloque solo gráfica y rejilla; corregir el ícono de búsqueda (hoy un punto negro) y el placeholder truncado a 360 px; verificar que `dashboard-redesign.spec.ts` pasa y que el campo se ve completo a 360 px.
- [x] 6.2 Reordenar el contenido por prioridad según `screen-patterns` y ajustar la composición de escritorio; verificar sin desbordamiento horizontal a 360 px.
- [x] 6.3 Aplicar el patrón `.state` a la carga, el vacío y el error del reporte y de los saldos; corregir que «Aún no tienes cobros.» aparece junto a cobros existentes (`.collections .empty:not([hidden])` anula `.records:not(:empty) + .empty` en `dashboard.astro`); verificar con una cuenta sin datos, una con cobros y un fallo de red simulado.
- [x] 6.4 Revisar la gráfica y las etiquetas de saldo en ambos temas; verificar contraste de barras y de las etiquetas de estado.

## 7. Listados

- [x] 7.1 Aplicar el patrón de listado a `payments/index.astro` con encabezado, tabla en escritorio y tarjetas en móvil; verificar paginación y filtros con los recorridos de `app-usability.spec.ts`.
- [x] 7.2 Aplicar el mismo patrón a `AccountCollection.astro` para clientes y proyectos con estado vacío con acción; verificar el texto «Aún no tienes proyectos» y el enlace «Crear mi primer proyecto».
- [x] 7.3 Probar nombres largos e importes altos a 360 px; verificar que no hay desbordamiento y que las acciones siguen visibles.

## 8. Formularios

- [x] 8.1 Migrar `IncomeSetupForm.tsx` (cliente y proyecto) a `.field`, `.btn` y barra de acciones común, con resumen de errores accesible; verificar `app-usability.spec.ts` y `income.spec.ts`.
- [x] 8.2 Migrar `PaymentForm.tsx` y `ProjectPaymentSummary.tsx`, uniendo las alturas de control, y mantener el resumen visible antes de guardar en móvil; verificar `project-payment-summary.spec.ts` y `payment-conversion.spec.ts`.
- [x] 8.3 Migrar `PaymentChargeForm.tsx`, `ProjectDelivery.tsx` y `DiscardChangesDialog.tsx`; verificar `payment-corrections.spec.ts` y `project-delivery.spec.ts`.
- [x] 8.4 Mover el foco al primer campo inválido tras un envío fallido en todos los formularios; verificar con una prueba que cubra cliente, proyecto y cobro.

## 9. Detalles

- [x] 9.1 Migrar `IncomePage.tsx` y `PaymentCorrections.tsx` a `.card`, `.metric` y jerarquía de acciones, con «Eliminar cobro» como acción de peligro; verificar `payment-corrections.spec.ts`.
- [x] 9.2 Migrar el detalle y la edición de proyecto (`projects/view.astro`, `projects/edit.astro`) con el panel de estado y el aviso de excedente; verificar `project-balances.spec.ts`.
- [x] 9.3 Verificar importes con cifras tabulares y moneda visible en MXN y USD; verificar con capturas del detalle de un cobro en cada moneda.

## 10. Cierre

- [x] 10.1 Buscar con `grep` usos restantes de `income-*` y `workspace-*`, migrarlos y retirar los alias; verificar que no quedan coincidencias y que Playwright pasa completo.
- [x] 10.2 Generar capturas finales en `docs/validation/screens/after/` a 360 y 1080 px en ambos temas y registrar la comparación con las referencias; verificar cuatro capturas por pantalla clave.
- [x] 10.3 Ejecutar Astro build, PHPUnit y Playwright completos y registrar resultados, y los pendientes físicos (PWA en teléfono, zoom al 200 %) en `docs/validation/ui-refinement.md`; verificar que todos los resultados quedan documentados.
- [x] 10.4 Cerrar T032 de `specs/006-improve-app-usability/tasks.md` con la evidencia anterior; verificar que no quedan tareas abiertas en 006.
