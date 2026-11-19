# Tareas de evolución financiera y tablero

**Entradas** — [Especificación](spec.md), [plan](plan.md), [decisiones](research.md), [modelo](data-model.md), [contratos](contracts/increments.md), [validación](quickstart.md) y [referencia visual](dashboard-reference.png).

## Criterios de revisión de cada implementación

Revisar las reglas de la constitución antes de cerrar cada tarea. Mantener identificadores y comentarios en inglés, interfaz en español de México y reglas de negocio fuera de los handlers y componentes visuales. Conservar exactitud decimal, pertenencia, privacidad en PWA, idempotencia y trazabilidad. Usar una base de pruebas independiente y verificar las migraciones sin descartar datos.

## Fase 1 · Preparación

- [X] T001 Verificar la línea base con `phpunit.xml`, `frontend/playwright.config.ts` y `specs/005-evolve-finance-dashboard/quickstart.md`. Ejecutar build, migraciones en la base de pruebas y ambas suites sobre HTTPS. Registrar resultados iniciales en `docs/validation/finance-dashboard.md`.

## Fase 2 · Fundamentos

- [X] T002 Confirmar y registrar en `docs/validation/finance-dashboard.md` los contratos de compatibilidad de las respuestas actuales de tablero, saldos y reporte. Usar `config/routes.php` y `frontend/src/lib/income-api.ts` como referencia para preservar sus consumidores, sesiones y política sin caché.

## Fase 3 · US1 · Revisar ingresos y saldos desde el nuevo tablero (P1)

**Prueba independiente.** Cargar los tres cobros de noviembre y el proyecto de ejemplo. Verificar MXN 6,723.00, tres enlaces y saldo de MXN 15,400.00 que permanece al cambiar de mes. Probar cero, uno y varios proyectos, dos páginas de cobros y reflujo a 360 px.

- [X] T003 [P] [US1] Preparar pruebas de contrato en `tests/Integration/AccountAccessTest.php` para contadores propios, suma exacta de saldos positivos, exclusión de excedentes del total, listado paginado con desempate estable y privacidad entre cuentas.
- [X] T004 [P] [US1] Preparar en `frontend/tests/dashboard-redesign.spec.ts` los recorridos de la muestra, meses sin datos, cargas fallidas, descarte de respuestas anteriores, selector de proyectos, historial y regresión a 360 px.
- [X] T005 [US1] Ampliar `src/Finance/Infrastructure/PostgresAccountDataReader.php`, `src/Finance/Infrastructure/PostgresProjectBalanceReportReader.php` y `src/Finance/Http/ProjectBalanceReportHandler.php` con los contadores y los agregados vigentes del contrato. Reutilizar la regla de saldo, sumar decimales exactos y conservar respuestas existentes.
- [X] T006 [US1] Incorporar `GET /api/payments` en `config/routes.php` mediante `src/Finance/Http/PaymentListHandler.php` y `src/Finance/Infrastructure/PostgresPaymentListReader.php`. Validar cursor por fecha e identificador, consultar solo cobros propios activos y conservar sesión y cabeceras privadas. Ejecutar T003.
- [X] T007 [US1] Rediseñar `frontend/src/pages/dashboard.astro` y `frontend/src/styles/theme.css` con la jerarquía de la referencia. Enlazar los tipos y lecturas de `frontend/src/lib/income-api.ts`. Compartir respuesta para tarjeta y lista mensual, conservar el filtro de saldos al cambiar de mes y distinguir carga, vacío y error.
- [X] T008 [US1] Completar acciones en `frontend/src/pages/dashboard.astro` y crear `frontend/src/pages/payments/index.astro` para «Ver todos» y `frontend/src/pages/clients/view.astro` para el enlace al detalle de cada cliente. Con cero proyectos ofrecer el alta, con uno abrir captura directa y con varios mostrar todos los propios con los pendientes primero y su estado visible. Mostrar los cuatro cobros recientes y proteger el listado privado y su caché mediante `frontend/astro.config.mjs` y la regla existente de `frontend/src/service-worker.ts`.
- [X] T009 [US1] Ejecutar T004 y las regresiones de privacidad y saldos. Comparar composición con `specs/005-evolve-finance-dashboard/dashboard-reference.png` en escritorio y a 360 px. Registrar evidencia en `docs/validation/finance-dashboard.md`.

## Fase 4 · US2 · Registrar la entrega del trabajo (P1)

**Prueba independiente.** Marcar como entregado un proyecto con saldo y comprobar que el saldo permanece. Abrir un proyecto liquidado aún en curso y completar un histórico con estado sin registrar.

- [X] T010 [P] [US2] Preparar pruebas en `tests/Integration/AccountAccessTest.php` y `tests/Integration/IncomeMigrationTest.php` para históricos sin estado, proyecto nuevo en curso, transiciones, validación, aislamiento, CSRF y conservación de saldos.
- [X] T011 [P] [US2] Preparar el recorrido de lectura y edición a 360 px en `frontend/tests/project-delivery.spec.ts`, con estado histórico, error recuperable y estados de entrega y cobro visibles por separado.
- [X] T012 [US2] Crear `db/migrations/20261119000200_add_project_delivery_status.php` con estado nullable y restricción. Conservar históricos nulos y bloquear una reversión que perdería estados registrados. Inicializar altas nuevas en curso con el valor predeterminado de la migración, incluido el flujo de `src/Finance/Application/CreateProject.php`.
- [X] T013 [US2] Ampliar `src/Finance/Application/OwnedResourceChanges.php`, `src/Finance/Http/OwnedResourceHandler.php` y `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` para exponer y guardar `deliveryStatus` mediante el detalle y el PATCH protegido del proyecto. Ejecutar T010.
- [X] T014 [US2] Integrar el control de entrega en `frontend/src/components/IncomePage.tsx` y tipar su respuesta en `frontend/src/lib/income-api.ts`. Ejecutar T011 y el caso de migración de `specs/005-evolve-finance-dashboard/quickstart.md`.

## Fase 5 · US5 · Conservar la protección de acceso detrás del proxy (P1)

**Prueba independiente.** Comprobar los contadores de dos orígenes con conexión directa y mediante el HTTPS configurado, conservando la política y el mensaje genérico.

- [X] T015 [P] [US5] Preparar pruebas de resolución de IP y separación de contadores con casos sintéticos en `tests/Unit/ClientIpResolverTest.php` y `tests/Integration/AccountAccessTest.php`. Cubrir conexión directa y topología autorizada.
- [X] T016 [US5] Implementar la resolución defensiva en `src/Shared/Http/ClientIpResolver.php` y pasar la IP normalizada al limitador desde `src/Identity/Http/LoginHandler.php`. Cablear la configuración de saltos autorizados en `config/routes.php` y `.env.example`. Conservar la dirección directa cuando la procedencia no sea confiable. Ejecutar T015.
- [ ] T017 [US5] Documentar la topología confiable y los ajustes en `frontend/astro.config.mjs` y `docs/local-development.md`. Validar acceso y separación de orígenes sobre HTTPS conforme a `specs/005-evolve-finance-dashboard/quickstart.md` y registrar el resultado en `docs/validation/finance-dashboard.md`.

## Fase 6 · US3 · Registrar un abono en otra moneda (P2)

**Prueba independiente.** Registrar USD 100.00 a 17.25, obtener MXN 1,725.00 y repetir la captura. Corregir moneda en ambos sentidos preservando identidad, cargos e historial. Verificar el saldo provisional del caso histórico antes y después de completar la tasa.

- [X] T018 [P] [US3] Preparar en `tests/Integration/AccountAccessTest.php` las pruebas de conversión ejecutadas por PostgreSQL exacta, medio centavo, deducciones acumuladas, residual del impuesto, deducción del 100 %, tasa y precisión inválidas.
- [X] T019 [P] [US3] Preparar en `tests/Integration/AccountAccessTest.php` las pruebas de históricos, versión, CSRF y pertenencia. Cubrir transiciones MXN ↔ USD, tasa omitida en edición ordinaria, confirmación obligatoria, auditoría y reintentos de alta anteriores y posteriores al cambio de moneda.
- [X] T020 [P] [US3] Preparar en `frontend/tests/payment-conversion.spec.ts` la captura USD, previsualización, corrección en ambas monedas, deducciones en moneda original y aviso de saldo provisional en detalle y tablero.
- [X] T021 [US3] Crear `db/migrations/20261119000300_add_payment_exchange_rate.php` para tasa decimal y auditoría de corrección, conservando filas históricas y protegiendo una reversión con datos nuevos. Definir límites de precisión y validar migración en la base de pruebas.
- [X] T022 [US3] Implementar la proyección monetaria compartida en `src/Finance/Application/PaymentConversion.php` con validación de tasa y los importes validados por `src/Finance/Application/PaymentAmounts.php`. Mantener MXN compatible y distinguir ausencia de equivalente. Ejecutar T018.
- [X] T023 [US3] Integrar previsualización, alta y corrección versionada en `src/Finance/Http/IncomeWriteHandler.php`, el flujo existente de correcciones, `src/Finance/Application/RecordPayment.php`, `src/Finance/Application/EditPayment.php` y `src/Finance/Infrastructure/PostgresPaymentCorrectionRepository.php`. Exigir confirmación e importes explícitos si cambia moneda, tasa nueva al pasar a USD y tasa nula en MXN. Guardar los valores anteriores sin alterar los cargos ni la huella original y preservar el contrato de reintentos.
- [X] T024 [US3] Compartir la proyección en `src/Finance/Application/PaymentExcess.php`, `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php`, `src/Finance/Infrastructure/PostgresAccountDataReader.php`, `src/Finance/Infrastructure/PostgresProjectBalanceReportReader.php` y `src/Finance/Infrastructure/PostgresMonthlyReportReader.php`. Contar históricos sin tasa, excluir su importe de sumas MXN y los proyectos afectados del saldo global y su conteo. Exponer el número de exclusiones. Ejecutar T019.
- [X] T025 [US3] Incorporar captura, edición y presentación de equivalentes en `frontend/src/components/PaymentForm.tsx`, `frontend/src/components/PaymentCorrections.tsx`, `frontend/src/components/IncomePage.tsx`, `frontend/src/components/ProjectPaymentSummary.tsx` y `frontend/src/lib/income-api.ts`. Mostrar en `frontend/src/pages/dashboard.astro` el aviso provisional y las exclusiones del agregado. Ejecutar T020 y las regresiones MXN.

## Fase 7 · US4 · Consultar y exportar el neto del mes (P2)

**Prueba independiente.** Un abono de MXN 1,500.00 con deducciones de MXN 150.00 y un cargo separado de MXN 75.00 el siguiente mes produce netos de MXN 1,350.00 y −75.00. El desglose y el CSV concuerdan para cada periodo y cuenta.

- [X] T026 [P] [US4] Preparar en `tests/Integration/AccountAccessTest.php` los casos de corte mensual, neto negativo, cobro eliminado, origen sin conversión, trazabilidad, paridad JSON/CSV y aislamiento.
- [X] T027 [P] [US4] Preparar en `frontend/tests/payment-conversion.spec.ts` el desglose, la descarga del periodo seleccionado y los enlaces al cobro desde abonos y cargos separados.
- [X] T028 [US4] Ampliar `src/Finance/Infrastructure/PostgresMonthlyReportReader.php` para derivar movimientos y totales exactos con las fechas de abono y de cargo respectivas, separar comisiones e impuestos y mantener el abonado existente. Reutilizar T024 para los equivalentes y representar los pendientes de conversión.
- [X] T029 [US4] Implementar la descarga en `src/Finance/Http/MonthlyReportHandler.php` y `config/routes.php` con la misma consulta y los mismos filtros. Entregar CSV UTF-8, escapar celdas como datos, exigir sesión con política sin caché y preservar la trazabilidad. Ejecutar T026.
- [X] T030 [US4] Mostrar neto y desglose en `frontend/src/pages/dashboard.astro` con los tipos de `frontend/src/lib/income-api.ts`, enlazar cada movimiento a su cobro y la descarga al periodo seleccionado. Ejecutar T027 y regresiones de tablero.

## Fase 8 · Validación transversal

- [ ] T031 Ejecutar `specs/005-evolve-finance-dashboard/quickstart.md` y registrar resultados en `docs/validation/finance-dashboard.md`. Verificar el cambio de cuenta con un cobro abierto, reapertura de PWA y gestores de contraseñas reales en Android físico. Si no hay dispositivo, registrar el caso pendiente.
- [X] T032 Ejecutar las suites descritas en `phpunit.xml` y `frontend/playwright.config.ts` contra la base de pruebas, comprobar migraciones y verificar exactitud, idempotencia, privacidad y revisión constitucional. Registrar evidencia por US1–US5 en `docs/validation/finance-dashboard.md`.

## Dependencias y trabajo en paralelo

- Preparación y fundamentos preceden los incrementos. El orden de producto es US1 → US2 → US3 → US4. US5 tiene entrega independiente tras fundamentos y puede avanzar junto con US1 o US2, coordinando las ediciones en `config/routes.php`.
- Las pruebas T003–T004, T010–T011, T018–T020 y T026–T027 se pueden preparar en paralelo dentro de sus fases. T015 puede prepararse de forma independiente tras fundamentos.
- T024 requiere T021–T023. T028 utiliza T024. Las tareas de cierre requieren los incrementos y las validaciones que indiquen sus criterios de salida.

## Estrategia de entrega

El MVP de esta fase es US1 con los datos actuales y las pruebas T003–T009. Se puede validar y entregar sin migraciones nuevas. US2 y US5 tienen sus propias comprobaciones. US3 se entrega cuando toda la ruta de captura y lectura usa la conversión aprobada. US4 añade el reporte ampliado y su exportación sobre esa base.
