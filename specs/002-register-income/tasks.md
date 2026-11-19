# Tareas de Registro de ingresos

**Entrada** — [Especificación](spec.md), [plan](plan.md), [investigación](research.md), [modelo](data-model.md), [contrato](contracts/income.md) y [guía de validación](quickstart.md).

Los identificadores `[P]` indican trabajo que puede ejecutarse en paralelo después de completar sus dependencias.

## Criterios de revisión de cada implementación

- Mantener aritmética exacta, escala explícita, moneda visible, conceptos monetarios distintos y estado de entrega independiente.
- Comprobar la pertenencia por cuenta, el guardado idempotente, la trazabilidad y la privacidad en la PWA.
- Probar las reglas fuera de Mezzio, Astro y React. Usar identificadores, comentarios y PHPDoc en inglés y textos de interfaz en español de México.
- Revisar responsabilidades, dependencias, reutilización de reglas y comentarios. Reservar los comentarios para explicar decisiones o restricciones relevantes.

## Fase 1 — Preparación

- [x] T001 Verificar PHP de Herd, dependencias PHP y frontend, origen HTTPS de Astro y conexión a PostgreSQL independiente para pruebas. Preparar la ejecución indicada en `specs/002-register-income/quickstart.md` con `composer.json`, `frontend/package.json` y `phpunit.xml`.

## Fase 2 — Fundamentos

- [ ] T002 [P] Preparar en `tests/Integration/IncomeMigrationTest.php` casos de migración desde filas históricas, segunda aplicación sin cambios, reversión y reaplicación en vacío, rechazo de titulares inconsistentes en cargos y bloqueo de reversión con origen, conteo acordado, razón o cargos capturados.
- [x] T003 [P] Definir en `tests/Unit/PaymentAmountsTest.php` casos de 14 dígitos, exceso de precisión, MXN 0.30 menos MXN 0.10 y MXN 0.20, deducciones omitidas o negativas y recibido de MXN 2,800.00.
- [x] T004 Implementar migraciones Phinx aditivas en `db/migrations/20261118000600_extend_income_projects.php`, `db/migrations/20261118000700_extend_income_payments.php` y `db/migrations/20261118000800_create_payment_charges.php`. Añadir monto y moneda acordados, origen, conteo acordado, fuente, deducciones y razón. Crear cargos con FK compuesta a `payments(user_id,id)` e idempotencia por cuenta, preservando filas y huellas históricas. Bloquear la reversión que descartaría datos y ejecutar T002.
- [x] T005 Implementar normalización y cálculo exacto en `src/Finance/Application/PaymentAmounts.php`, reutilizando la regla de `src/Finance/Application/ClientPaidAmount.php` y diferenciando montos positivos de deducciones no negativas. Producir cadenas de dos decimales y ejecutar T003.
- [x] T006 Crear en `frontend/src/lib/income-api.ts` el acceso común a JSON del mismo origen con errores por campo y reutilización de CSRF de `frontend/src/lib/auth-api.ts`. Integrar las páginas nuevas con `frontend/src/lib/private-session.ts` para ocultar datos al perder conexión y limpiar datos y operaciones al invalidarse o cambiar la sesión. Extender no-store en `frontend/astro.config.mjs` y verificar la exclusión del service worker en `frontend/src/service-worker.ts`.

## Fase 3 — US1 · Dar de alta un cliente y su proyecto (P1)

**Prueba independiente.** Desde una cuenta vacía, crear «Estudio Norte» y «Identidad visual» con MXN 5,000.00, origen «Recomendación» y tres pagos acordados. Ver «0 de 3 pagos recibidos» y seleccionar el mismo cliente para un segundo proyecto.

- [ ] T007 [P] [US1] Preparar en `tests/Integration/AccountAccessTest.php` pruebas de contrato para POST de clientes y proyectos y GET de proyecto. Cubrir nombres recortados, monto acordado en MXN, rechazo de USD, origen, conteo acordado positivo entero, históricos, sesión, CSRF y cliente ajeno o inexistente con respuesta idéntica.
- [ ] T008 [P] [US1] Preparar en `frontend/tests/income.spec.ts` el recorrido desde tablero vacío, el alta de cliente, el retorno con cliente seleccionado, la reutilización del cliente y el detalle inicial con origen y «0 de 3 pagos recibidos».
- [x] T009 [US1] Extender `src/Finance/Application/CreateProject.php` para validar y guardar monto positivo en MXN, origen y cantidad acordada de pagos y preservar los llamadores anteriores, y reutilizar `src/Finance/Application/CreateClient.php`. Preparar en `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` la lectura de detalle por cuenta con datos históricos nulos, conteo real y suma decimal de pagos.
- [x] T010 [US1] Añadir POST protegidos y ampliar GET de proyecto en `config/routes.php` y emplear `src/Finance/Http/OwnedResourceHandler.php` para las lecturas existentes. Aplicar la semántica del contrato de `specs/002-register-income/contracts/income.md` y ejecutar T007.
- [x] T011 [US1] Implementar alta y selección en `frontend/src/pages/clients/new.astro`, `frontend/src/pages/projects/new.astro` y `frontend/src/components/IncomeSetupForm.tsx`. Integrar accesos desde `frontend/src/pages/dashboard.astro` y detalle en `frontend/src/pages/projects/view.astro`. Capturar y mostrar origen, monto acordado y avance. Mostrar «Sin registrar» para los datos históricos ausentes. Ejecutar T008.

## Fase 4 — US2 · Registrar un cobro desde un proyecto (P1)

**Prueba independiente.** En un proyecto propio, registrar un abono de MXN 3,000.00 con MXN 150.25 de comisión y MXN 49.75 de impuestos. Ver MXN 2,800.00 recibidos. Registrar MXN 800.00 de otra fuente y verificar ambos pagos y el avance. En un proyecto de MXN 5,000.00 con MXN 4,800.00 abonados, verificar el aviso de MXN 300.00 al capturar MXN 500.00 y guardar con razón.

- [ ] T012 [P] [US2] Preparar en `tests/Integration/AccountAccessTest.php` pruebas de contrato de previsualización sin escritura, alta, detalle, autorización, fecha, rechazo de USD, deducciones, fuente, registros históricos, igualdad con el monto acordado, exceso con y sin razón, y límites de edición heredada. Guardar sin excedente, perder la respuesta, registrar otro cobro que supere el acuerdo y repetir la primera solicitud. Verificar el mismo identificador y ninguna escritura adicional. Comparar el proyecto antes y después de guardar, incluido el estado «Entregado» cuando ese dato exista. Mientras el esquema carezca de estado de entrega, verificar que la fila del proyecto se conserva y registrar pendiente el caso con estado explícito.
- [x] T013 [P] [US2] Preparar en `frontend/tests/income.spec.ts` la captura a 360 px sin desplazamiento horizontal, el cálculo previo, la corrección de precisión sin pérdida de otros datos dos pagos con fuente y conteo correctos, y aviso de excedente con razón que se conserva en el detalle.
- [x] T014 [US2] Validar fecha de pago con el reloj de `src/Shared/Clock/Clock.php` y zona `America/Mexico_City`, MXN, tipo de fuente, nombre condicional y razón de 1 a 250 caracteres recortados cuando se requiera en `src/Finance/Application/PaymentInput.php`. Extender el registro de `src/Finance/Application/RecordPayment.php` y su huella con valores normalizados, preservando la variante histórica. Tras verificar propiedad y antes de validar el excedente, resolver la clave confirmada por cuenta con la huella normalizada. Mantener la unicidad y la lectura tras conflicto concurrente.
- [x] T015 [US2] Mantener la asociación con el cliente en `src/Finance/Application/RecordPayment.php` y `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` mediante bloqueo común de proyecto, comprobación posterior al bloqueo y rechazo de reasignaciones con pagos. Calcular con numeric el total vigente y exigir la razón antes de insertar. En el PATCH heredado, bloquear primero el proyecto y luego el cobro, proteger las deducciones y rechazar incrementos que provoquen o aumenten un excedente.
- [x] T016 [US2] Incorporar previsualización y alta protegidas en `config/routes.php` y `src/Finance/Http/IncomeWriteHandler.php`. Reutilizar el cálculo de T005 y la regla de excedente de T015, devolver el excedente vigente en 201, el cobro original en 200, el conflicto de huella en 409 y el total proyectado con la diferencia actualizada en 422 si falta razón y ampliar los GET correspondientes mediante `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php`. Ejecutar T012.
- [x] T017 [US2] Implementar `frontend/src/pages/payments/new.astro`, `frontend/src/pages/payments/view.astro` y `frontend/src/components/PaymentForm.tsx`. Usar una sola pantalla con fecha propuesta, fuente, importes diferenciados, previsualización y aviso de excedente con razón obligatoria, ignorar respuestas de cálculo obsoletas, enviar una clave por captura desde el primer guardado y conservar los campos válidos ante errores y mantener los datos y la clave si el guardado exige una razón por un cambio concurrente. Si ya hay una razón válida, mostrar en la confirmación el excedente devuelto por el guardado.
- [x] T018 [US2] Enlazar la captura y el detalle desde `frontend/src/pages/projects/view.astro`. Verificar total abonado, monto acordado independiente, avance real aunque supere la cantidad acordada, origen, fuentes y detalle histórico. Ejecutar T013. US2 solo habilita la captura tras pasar T012, incluido el reintento de un cobro confirmado después de que el total haya cambiado.

### Cargos pagados por separado en el detalle

**Prueba independiente.** Asociar a un cobro propio de noviembre una comisión y un impuesto pagados en diciembre. Ver ambas fechas, un solo registro ante reintentos y el importe recibido original.

- [ ] T019 [P] [US2] Preparar en `tests/Integration/AccountAccessTest.php` las pruebas de contrato de alta y detalle con cargo, sesión, CSRF, cobro ajeno o inexistente, tipo, fecha, importe, USD, idempotencia concurrente y conflicto. Verificar que los cargos no alteran recibido, cantidad de pagos ni agregados.
- [x] T020 [P] [US2] Preparar en `frontend/tests/income.spec.ts` la captura móvil de comisión e impuesto con fechas distintas, reintento ante respuesta perdida, nueva captura explícita y lista en el detalle.
- [x] T021 [US2] Implementar alta de cargo en `src/Finance/Application/RecordPaymentCharge.php`, con titular validado antes de resolver la clave, tipo, fecha, precisión de MXN y huella propia. Reutilizar la validación de importe positivo y la política de fecha de T014.
- [x] T022 [US2] Añadir POST de cargos en `config/routes.php` y `src/Finance/Http/IncomeWriteHandler.php`. Ampliar el GET de cobro en `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` para listar cargos por fecha e ID sin multiplicar filas del padre. Ejecutar T019.
- [x] T023 [US2] Incorporar la captura de cargos en `frontend/src/components/PaymentChargeForm.tsx` y su listado en `frontend/src/pages/payments/view.astro`. Reutilizar `frontend/src/lib/income-api.ts` y la política de claves y limpieza por sesión de T006 y T017 para cada operación de cargo. Ejecutar T020.

## Fase 5 — US3 · Reintentar sin duplicar el abono (P1)

**Prueba independiente.** Simular doble toque, pérdida de respuesta después del guardado, conflicto con datos distintos y una captura nueva de los mismos datos.

- [ ] T024 [P] [US3] Preparar en `tests/Integration/IncomeIdempotencyTest.php` pruebas de concurrencia con conexiones independientes, 201/200, conflicto 409 sin cambios, aislamiento de claves por cuenta, huella histórica, reintento tras el PATCH heredado y dos cobros simultáneos que juntos cruzan el monto acordado. Comprobar que un reintento anterior al exceso devuelve el mismo cobro y que un 422 permite corregir con la misma clave. Previsualizar MXN 300.00 de excedente con razón válida, elevarlo a MXN 600.00 en otra conexión y verificar el 201 con MXN 600.00 sin otra confirmación.
- [x] T025 [P] [US3] Preparar en `frontend/tests/income.spec.ts` pruebas de doble toque, respuesta perdida con guardado efectivo, reintento con misma clave y dos capturas explícitas con claves distintas.
- [ ] T026 [US3] Ejecutar la matriz de concurrencia T024 y cubrir regresiones de la resolución idempotente habilitada en US2 en `src/Finance/Application/RecordPayment.php` y `src/Finance/Http/IncomeWriteHandler.php`, incluida la concurrencia entre proyectos con una misma clave por cuenta.
- [x] T027 [US3] Mantener clave y datos en memoria por operación en `frontend/src/components/PaymentForm.tsx` y enviar `Idempotency-Key` desde `frontend/src/lib/income-api.ts`. Conservarla en el reintento y renovarla en una captura nueva. Limpiar el estado al salir o invalidar la sesión. Ejecutar T025.

## Fase 6 — US4 · Consultar el cobro en el periodo correcto (P1)

**Prueba independiente.** Con dos cuentas y pagos el 30 de noviembre y 1 de diciembre, comprobar totales por mes, acceso al detalle y denegación uniforme de recursos ajenos.

- [x] T028 [P] [US4] Preparar en `tests/Integration/AccountAccessTest.php` la prueba de contrato para reporte por cuenta, mes y MXN. Cubrir cambios de mes, reporte vacío, suma abonada frente a recibido, cargos de otros meses que no se cuentan y GET o POST con identificadores ajenos.
- [ ] T029 [P] [US4] Preparar en `frontend/tests/income.spec.ts` casos de captura móvil consultable después desde escritorio, enlaces al detalle, desconexión con la vista abierta, recarga sin datos privados y cambio de cuenta.
- [x] T030 [US4] Reutilizar `src/Finance/Infrastructure/PostgresMonthlyReportReader.php` para mantener la suma decimal y el intervalo semiabierto, con identificadores propios para la trazabilidad. Preservar el contrato de lectura en `config/routes.php` y ejecutar T028.
- [x] T031 [US4] Enlazar las filas del reporte en `frontend/src/pages/dashboard.astro` a `frontend/src/pages/payments/view.astro`, etiquetar el total como importe abonado y presentar el estado vacío. Verificar ocultación al perder conexión y ejecutar T029.

## Fase 7 — Validación integral

- [x] T032 Documentar en `docs/local-development.md` las nuevas rutas privadas, cabeceras no-store y política sin caché, y en `specs/002-register-income/quickstart.md` el procedimiento verificable para respaldar y recuperar los datos antes de una reversión que pudiera descartarlos.
- [ ] T033 Ejecutar la guía de `specs/002-register-income/quickstart.md` en base independiente con migraciones, PHPUnit, Playwright y PWA bajo HTTPS. Registrar resultados y cualquier caso pendiente en `docs/validation/register-income.md`. Comprobar regresión de acceso por cuenta y conformidad con los criterios de revisión.

Las tareas abiertas de pruebas conservan los escenarios aún pendientes. Consulta los [resultados de validación](../../docs/validation/register-income.md).

## Dependencias y ejecución

```text
Preparación → Fundamentos → US1 → US2 → US3 → US4 → Validación integral
```

US2 requiere el recorrido de proyecto de US1. US2 entrega el guardado idempotente completo en el servidor. US3 amplía las pruebas de concurrencia y el recorrido de reintento en el navegador. Los cargos se validan dentro de US2 porque forman parte del detalle del cobro. US4 utiliza los cobros registrados y sus detalles. Dentro de cada historia, las pruebas se pueden escribir con datos de prueba propios una vez disponibles los fundamentos y los contratos, antes de que existan todas las pantallas.

| Fase | Ejemplo de ejecución en paralelo |
| --- | --- |
| Fundamentos | T002 y T003, en archivos de pruebas distintos |
| US1 | T007 y T008, contrato PHP y recorrido web |
| US2 | T012 y T013 para cobros, y T019 y T020 para cargos |
| US3 | T024 y T025, concurrencia y reintento desde el navegador |
| US4 | T028 y T029, reporte y privacidad en navegador |

## Estrategia de implementación

US1 es la primera entrega verificable. El **MVP de Registro de ingresos** abarca US1–US4: alta de cliente y proyecto, captura de cobros con motivo de excedente, cargos separados, reintentos seguros y consulta del reporte trazable. Las cuatro historias son P1. La validación integral precede al cierre del MVP.

| Historia | Cobertura de la especificación |
| --- | --- |
| US1 | FR-001, FR-007, FR-010, FR-013, FR-017 y SC-006, SC-009 |
| US2 | FR-001–FR-007, FR-009, FR-011–FR-012, FR-014–FR-017 y SC-001–SC-003, SC-006–SC-009 |
| US3 | FR-006, FR-009, FR-014 y SC-003, SC-007 |
| US4 | FR-007–FR-009, FR-015 y SC-004–SC-005, SC-008 |
