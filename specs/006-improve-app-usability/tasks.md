# Tareas · Usabilidad de Flow Balance

**Entradas** · [Especificación](spec.md), [plan](plan.md), [decisiones](research.md), [modelo](data-model.md), [contratos](contracts/usability.md), [validación](quickstart.md) y [referencias](references/index.md).

## Criterios de revisión de cada implementación

Revisar la [constitución](../../.specify/memory/constitution.md). Mantener identificadores y comentarios en inglés, interfaz es-MX, cálculos decimales en el dominio o en consultas compartidas, estados de entrega y cobro independientes, e idempotencia y trazabilidad de cobros. Verificar pertenencia, CSRF, privacidad de caché y reflujo en móvil. Ejecutar escrituras de prueba en una base independiente.

## Fase 1 · Preparación

- [X] T001 Comprobar compilación de Astro, PHPUnit y Playwright sobre HTTPS con la base aislada. Registrar línea base y fallos previos en `docs/validation/app-usability.md` siguiendo `specs/006-improve-app-usability/quickstart.md`.

## Fase 2 · Fundamentos

- [X] T002 Separar del CSS existente los tokens semánticos necesarios para fondo, superficies, texto, borde, foco, acento y peligro en `frontend/src/styles/theme.css`. Conservar el aspecto oscuro y el orden de carga actual de `frontend/src/layouts/AppLayout.astro`.

## Fase 3 · US1 · Navegación y acciones coherentes (P1)

**Prueba independiente.** Recorrer tablero, cobros, clientes y proyectos en escritorio y a 360 px. Verificar sección activa, foco, cancelación de un borrador y confirmación de eliminación con fecha e importe.

- [X] T003 [US1] Implementar navegación privada compartida en `frontend/src/components/PrivateNavigation.astro` y montarla desde `frontend/src/layouts/AppLayout.astro` en las pantallas privadas. Retirar la duplicación en `frontend/src/pages/dashboard.astro`. Habilitar los cuatro destinos y resaltar el activo. Clientes y Proyectos pueden enlazar a sus secciones del tablero hasta que T028 habilite sus listados propios. Mostrar etiquetas y respetar área segura en móvil.
- [X] T004 [US1] Definir estilos primario, secundario, peligro, enfoque y operación en curso en `frontend/src/styles/theme.css`. Aplicarlos a `frontend/src/components/IncomeSetupForm.tsx`, `frontend/src/components/PaymentForm.tsx`, `frontend/src/components/PaymentCorrections.tsx`, `frontend/src/components/PaymentChargeForm.tsx` y `frontend/src/pages/dashboard.astro`.
- [X] T005 [US1] Incorporar confirmación al cancelar cambios en los formularios mediante un diálogo compartido en `frontend/src/components/DiscardChangesDialog.tsx`. Conservar el contexto al cancelar, restaurar foco, y asegurar que la confirmación destructiva de `frontend/src/components/PaymentCorrections.tsx` muestra fecha e importe y mantiene el flujo vigente.
- [X] T006 [US1] Validar navegación, foco, acción primaria, descarte y eliminación con cancelación en `frontend/tests/app-usability.spec.ts` y las regresiones de `frontend/tests/payment-corrections.spec.ts`.

## Fase 4 · US2 · Tema claro u oscuro (P1)

**Prueba independiente.** Abrir sin preferencia, cambiar a claro desde la landing, recargar, abrir tablero y PWA, y recuperar oscuro desde una vista privada.

- [X] T007 [US2] Definir la paleta clara en `frontend/src/styles/theme.css` y aplicar el tema inicial antes del primer renderizado en `frontend/src/layouts/AppLayout.astro`. Implementar lectura y escritura de la preferencia local en `frontend/src/lib/theme-preference.ts`, con oscuro como valor inicial y recuperación ante almacenamiento no disponible.
- [X] T008 [US2] Incorporar control con estado y nombre accesibles mediante `frontend/src/components/ThemeSelector.astro` en `frontend/src/components/SiteHeader.astro` y `frontend/src/components/PrivateNavigation.astro`. Verificar Inter, contraste, campos y estados en landing, autenticación y vistas privadas.
- [X] T009 [US2] Validar cambio de tema, persistencia, primera visita y navegación entre landing y tablero en `frontend/tests/app-usability.spec.ts`. Registrar reapertura física de PWA o su estado pendiente en `docs/validation/app-usability.md`.

## Fase 5 · US3 · Historial y periodos (P1)

**Prueba independiente.** Con siete cobros y páginas de dos filas, verificar MXN 24,323.00 en Todos y MXN 6,723.00 en noviembre de 2026. Aplicar un mes histórico y comprobar que cancelar y Escape conservan el periodo y los totales. Verificar monedas mixtas y cuenta ajena.

- [X] T010 [P] [US3] Preparar casos para filtro, moneda mixta, mes vacío, meses rápidos, compatibilidad de cursor e aislamiento en `tests/Integration/AccountAccessTest.php`.
- [X] T011 [P] [US3] Preparar recorrido de selector y paginación en `frontend/tests/dashboard-redesign.spec.ts` y `frontend/tests/app-usability.spec.ts` y ajustar selectores reutilizables en `frontend/tests/helpers/select-report-month.ts`. Cubrir fecha y mes, cancelación, año lejano y respuesta tardía de otro filtro.
- [X] T012 [P] [US3] Extraer el diálogo de `frontend/src/pages/dashboard.astro` a `frontend/src/components/MonthPicker.astro` y usarlo en tablero. Guardar el borrador por separado, aplicar explícitamente, desactivar meses futuros y restaurar foco. Mantener selección directa desde la gráfica.
- [X] T013 [US3] Ampliar `src/Finance/Infrastructure/PostgresPaymentListReader.php` y `src/Finance/Http/PaymentListHandler.php` con mes opcional, cursor compatible, resumen antes de paginar, máximo de fecha, agrupación exacta por moneda y tres meses rápidos de la cuenta. Conservar los campos de respuesta y el acceso privado.
- [X] T014 [US3] Rediseñar `frontend/src/pages/payments/index.astro` con resumen completo, filtros rápidos y selector compartido, estados de carga/vacío/error, tabla de escritorio y filas móviles. Reiniciar cursor y descartar respuestas obsoletas al cambiar filtro. Tipar la respuesta en `frontend/src/lib/income-api.ts`.
- [X] T015 [US3] Ejecutar T010 y T011, además de las regresiones del reporte y CSV en `frontend/tests/payment-conversion.spec.ts`.

## Fase 6 · US4 · Alta de clientes y edición de acuerdos (P2)

**Prueba independiente.** Crear un cliente desde el proyecto sin perder el borrador, comprobar el promedio por pago y corregir datos del alta. Con MXN 6,600.00 abonados, bajar a MXN 6,000.00, ver MXN 600.00 de excedente y verificar rechazo sin motivo y una sola revisión con motivo.

- [X] T016 [P] [US4] Preparar casos de cambio de acuerdo, motivo, auditoría atómica, cuenta ajena, reintento, reasignación y concurrencia con cobros en `tests/Integration/AccountAccessTest.php`. Cubrir proyección provisional y redondeo por pago.
- [X] T017 [P] [US4] Preparar recorridos de borrador, validación, alta y edición en `frontend/tests/app-usability.spec.ts`. Comprobar retorno al proyecto con cliente nuevo, corrección de campos y confirmación cuando se genera excedente.
- [X] T018 [P] [US4] Crear `db/migrations/20261119000400_create_project_agreement_revisions.php` con la pertenencia compuesta, importes exactos, motivo y fecha definidos en el modelo. Impedir reversión con revisiones guardadas y comprobar reversión vacía y reaplicación en `tests/Integration/IncomeMigrationTest.php`.
- [X] T019 [US4] Implementar validación y proyecciones de acuerdo en `src/Finance/Application/ProjectAgreement.php`, reutilizando las reglas de `src/Finance/Application/CreateProject.php`, `src/Finance/Application/ProjectBalance.php` y la proyección de abonos MXN. Recalcular bajo bloqueo de proyecto al guardar. Exigir motivo solo si hay reducción efectiva con excedente y conservar las restricciones de cliente y estado de entrega.
- [X] T020 [US4] Persistir acuerdo y revisión atómica en `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php`. Ampliar PATCH mediante `src/Finance/Application/OwnedResourceChanges.php` y `src/Finance/Http/OwnedResourceHandler.php`. Exponer vista previa privada en `src/Finance/Http/IncomeWriteHandler.php` y `config/routes.php`. Conservar los PATCH anteriores y devolver errores de campo.
- [X] T021 [US4] Rediseñar alta y panel de clientes en `frontend/src/pages/clients/new.astro` y `frontend/src/components/IncomeSetupForm.tsx`. Mantener el borrador del proyecto ligado a su sesión al abrir el alta, seleccionar al cliente nuevo al volver y limpiar el borrador al terminar el flujo o cerrar sesión.
- [X] T022 [US4] Integrar alta y edición en `frontend/src/pages/projects/new.astro`, `frontend/src/pages/projects/view.astro`, `frontend/src/pages/projects/edit.astro` y `frontend/src/components/IncomePage.tsx`. Mostrar vista previa por pago, panel de estado y motivo con excedente proyectado. Conservar datos históricos ausentes y actualizar el panel al guardar. Ejecutar T016–T018.

## Fase 7 · US5 · Registro, corrección y detalle de cobros (P2)

**Prueba independiente.** Registrar desde Cobros en MXN y USD, validar neto y saldo proyectado, editar sin mover de proyecto, y registrar una comisión o un impuesto en otro mes. Un doble guardado produce un solo cobro.

- [X] T023 [P] [US5] Preparar en `tests/Integration/AccountAccessTest.php` casos de reemplazo, versiones desactualizadas, rechazo de `projectId`, CSRF, pertenencia, conversión provisional y cargos de fecha distinta.
- [X] T024 [P] [US5] Preparar en `frontend/tests/app-usability.spec.ts` e `frontend/tests/income.spec.ts` recorridos desde Cobros y desde Proyecto, preselección, cambio de proyecto en alta, vista previa, corrección con proyecto fijo, doble toque y alta de cargo.
- [X] T025 [US5] Extender la proyección de `src/Finance/Application/PaymentExcess.php` y el flujo de `src/Finance/Application/EditPayment.php` para calcular el reemplazo con la misma regla de guardado. Adaptar `src/Finance/Http/IncomeWriteHandler.php`, `src/Finance/Http/PaymentCorrectionHandler.php` y `config/routes.php` para lectura propia y versionada, conservar idempotencia y rechazar reasignación.
- [X] T026 [US5] Rediseñar `frontend/src/pages/payments/new.astro`, `frontend/src/components/PaymentForm.tsx` y `frontend/src/components/ProjectPaymentSummary.tsx` con selección de proyecto, preselección desde detalle, neto y avance proyectado. Mantener USD, equivalentes y saldo provisional. Invalidar la vista previa al cambiar de proyecto y guiar a crear uno cuando no exista.
- [X] T027 [US5] Rediseñar `frontend/src/pages/payments/view.astro`, `frontend/src/components/IncomePage.tsx`, `frontend/src/components/PaymentCorrections.tsx` y `frontend/src/components/PaymentChargeForm.tsx` con tarjetas de importes, acciones diferenciadas, proyecto de solo lectura en edición y panel de cargos Comisión/Impuesto. Ejecutar T023 y T024.

## Fase 8 · US6 · Tablero y flujos en móvil (P2)

**Prueba independiente.** A 360 px y zoom de 200 %, recorrer el tablero, los listados, el alta y el detalle. Verificar que la barra inferior no cubre el contenido y que todavía se puede abrir el reporte y descargar CSV.

- [X] T028 [P] [US6] Implementar listados privados en `frontend/src/pages/clients/index.astro` y `frontend/src/pages/projects/index.astro` con alta, detalle, estados vacíos y orden estable. Mostrar cliente en la fila de proyecto, reutilizar lecturas de cuenta y actualizar los destinos de `frontend/src/components/PrivateNavigation.astro` a los listados nuevos.
- [X] T029 [P] [US6] Adaptar `frontend/src/pages/dashboard.astro` al orden móvil de la referencia. Integrar Registrar cobro a la tarjeta principal, mantener dos indicadores compactos, gráfica, saldos, cobros y acceso a listados. Conservar el reporte detallado y el CSV.
- [X] T030 [US6] Ajustar navegación y reflujo en `frontend/src/components/PrivateNavigation.astro` y `frontend/src/styles/theme.css`. Validar una columna en formularios y detalles, resumen accesible antes del guardado y áreas seguras.
- [X] T031 [US6] Verificar en `frontend/tests/app-usability.spec.ts` navegación, anchura, controles y recorrido de alta. Comparar las referencias a 360 y 1080 px en ambos temas y registrar diferencias en `docs/validation/app-usability.md`. Cubierta por el cambio de OpenSpec `refine-app-ui-ux` (tareas 1.5 y 10.2): comparación y capturas en `docs/validation/ui-refinement.md`.

## Fase 9 · Revisión transversal

- [X] T032 Ejecutar suites, revisión constitucional, comprobaciones de migración y los recorridos de `specs/006-improve-app-usability/quickstart.md`. Registrar evidencia por historia, privacidad y pendientes físicos en `docs/validation/app-usability.md`. Evidencia en `docs/validation/ui-refinement.md`. Quedan como pendientes físicos la reapertura de la PWA en un teléfono y el zoom al 200 % en un dispositivo real.

## Dependencias y orden de ejecución

| Historia o fase | Depende de | Paralelismo comprobable |
| --- | --- | --- |
| Preparación y fundamentos | T001 → T002 | Completar antes de US1. |
| US1 | T002 | Navegación, estilos y descarte se integran en T006. |
| US2 | US1 | T007 → T008 → T009. |
| US3 | US1 y US2 | T010, T011 y T012 pueden avanzar en paralelo. Ejecutar T014 después de T012 y T013. |
| US4 | US1 y US2 | T016, T017 y T018 pueden avanzar en paralelo y empezar mientras se desarrolla US3. Coordinar `frontend/src/lib/income-api.ts` si ambos incrementos lo modifican. |
| US5 | US1 y US2 y resumen de proyecto existente | T023 y T024 pueden avanzar en paralelo. Coordinar cambios en `IncomePage.tsx` y rutas con US4. |
| US6 | US1 a US5 | T028 y T029 pueden avanzar en paralelo. T030 y T031 verifican integración. |
| Revisión | US1 a US6 | T032 cierra el recorrido completo. |

## Estrategia de implementación

El primer incremento y MVP de diseño comprende **US1 y US2**. Entrega navegación, acciones y temas consistentes conservando los flujos actuales. Después se pueden entregar historial y selector (**US3**), acuerdos y altas (**US4**), cobros (**US5**) y el cierre móvil (**US6**). Cada entrega se valida de forma aislada antes de integrarse.
