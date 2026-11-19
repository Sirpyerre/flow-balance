# Tareas de Corrección de cobros

**Entrada** — [Especificación](spec.md), [plan](plan.md), [modelo](data-model.md), [contrato](contracts/payment-corrections.md) y [validación](quickstart.md).

## Criterios de revisión de cada implementación

- Usar decimales exactos y conservar los datos originales, la pertenencia por cuenta y la trazabilidad de los importes.
- Probar reglas fuera de HTTP y mantener autorización, CSRF, privacidad sin conexión e idempotencia.
- Usar identificadores y comentarios en inglés, textos en español de México y reutilizar las reglas existentes.

## Fase 1 · Preparación

- [X] T001 Verificar la línea base con `phpunit.xml`, `frontend/playwright.config.ts` y `specs/004-correct-payments/quickstart.md`. Ejecutar las suites existentes en PostgreSQL independiente y Chrome bajo HTTPS.

## Fase 2 · Fundamentos

- [X] T002 [P] Preparar en `tests/Integration/IncomeMigrationTest.php` casos de migración con filas históricas, pertenencia del historial, claves de edición únicas por cobro y reversión protegida ante datos de corrección o bajas.
- [X] T003 [P] Preparar en `tests/Unit/PaymentCorrectionTest.php` casos de monto, fecha, fuente, deducciones y evaluación de excedente. Cubrir correcciones que conservan, reducen, crean o aumentan el excedente y redondeo por abono.
- [X] T004 Crear `db/migrations/20261119000100_add_payment_corrections.php` con versión, fecha de baja e historial con actor, acción, motivo opcional, datos exactos anteriores y versión. Proteger pertenencia, unicidad de claves por cobro e integridad de huella y versiones. Inicializar cobros existentes como activos y preservar sus claves de alta. Ejecutar T002.
- [X] T005 Aplicar la condición de actividad y exponer la versión en el detalle desde `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php`, `src/Finance/Infrastructure/PostgresAccountDataReader.php`, `src/Finance/Infrastructure/PostgresMonthlyReportReader.php`, `src/Finance/Infrastructure/PostgresProjectBalanceReportReader.php` y `src/Finance/Application/PaymentExcess.php`. Revisar consultas de reasignación de cliente y conservar la detección de historial. Mantener deliberadamente separadas las búsquedas de claves de alta.
- [X] T006 Crear en `src/Finance/Infrastructure/PostgresPaymentCorrectionRepository.php` las lecturas y escrituras comunes de correcciones. Asegurar el orden de bloqueo proyecto → cobro, la verificación de pertenencia tras el bloqueo, la escritura de historial y la consulta de reintentos. Usar la transacción por petición de `src/Shared/Database/UnitOfWork.php`.

## Fase 3 · US1 · Editar un cobro propio (P1)

**Prueba independiente.** Cambiar un abono de MXN 3,000.00 en noviembre a MXN 2,500.00 en diciembre para un proyecto de MXN 5,000.00. Verificar los dos reportes, el saldo de MXN 2,500.00, el cargo separado intacto y un solo asiento de historial tras perder la respuesta y reintentar.

- [X] T007 [P] [US1] Preparar pruebas de GET y PATCH en `tests/Integration/AccountAccessTest.php` para aislamiento, CSRF, compatibilidad del PATCH mínimo, cambio de mes, saldo, cargos intactos y versiones. Cubrir reintento 200 sin duplicar historial, 409 por clave con contenido distinto y 412 tras una edición posterior.
- [X] T008 [P] [US1] Preparar pruebas en `frontend/tests/payment-corrections.spec.ts` para editar desde el detalle a 360 px, conservar campos ante errores, recuperar una respuesta perdida y pedir revisión si la versión cambió.
- [X] T009 [US1] Implementar en `src/Finance/Application/EditPayment.php` validación con `src/Finance/Application/PaymentInput.php` y `src/Finance/Application/PaymentAmounts.php`. Comparar con el excedente anterior usando la normalización aprobada y exigir razón al crearlo o aumentarlo. Guardar la huella del intento y auditar el cambio sin modificar cargos ni la clave original de alta. Ejecutar T003.
- [X] T010 [US1] Integrar edición en `config/routes.php` y `src/Finance/Http/PaymentCorrectionHandler.php`. Definir la representación de la versión esperada y su validación conforme al contrato. Mantener el PATCH mínimo auditado y versionado. Resolver reintentos bajo bloqueo antes de rechazar una versión anterior. Ejecutar T007.
- [X] T011 [US1] Integrar el formulario `frontend/src/components/PaymentCorrections.tsx` en `frontend/src/components/IncomePage.tsx` y ampliar `frontend/src/lib/income-api.ts`. Conservar en memoria clave, versión y contenido para el mismo reintento. Pedir revisión de la versión vigente ante conflicto, proteger datos privados y actualizar el detalle tras confirmar. Ejecutar T008.

## Fase 4 · US2 · Eliminar un cobro propio (P1)

**Prueba independiente.** Cancelar la baja de un cobro con cargo separado y conservar los datos. Confirmarla, probar con y sin motivo, y comprobar el saldo y reporte recalculados, el historial intacto y que el reintento del alta original no reactive el cobro.

- [X] T012 [P] [US2] Preparar pruebas en `tests/Integration/AccountAccessTest.php` para DELETE con y sin motivo, permisos, CSRF, 428, 412, reintento 204, detalle eliminado 404, alta repetida 409 y consistencia de todas las lecturas activas. Cubrir carreras entre edición, alta de cargo y eliminación con dos conexiones.
- [X] T013 [P] [US2] Preparar pruebas en `frontend/tests/payment-corrections.spec.ts` para la confirmación con importe, fecha y cargos, cancelación, motivo opcional, fallo de red, conflicto de versión y regreso al proyecto con saldo actualizado a 360 px.
- [X] T014 [US2] Implementar `src/Finance/Application/DeletePayment.php` con baja lógica y auditoría atómicas. Coordinar el alta de cargos en `src/Finance/Application/RecordPaymentCharge.php` mediante el bloqueo del padre y aumento de versión al crear un cargo. Preservar el resultado ante reintentos y rechazar escrituras sobre bajas. Ajustar `src/Finance/Application/RecordPayment.php` para distinguir una clave de alta ya reservada por un cobro eliminado.
- [X] T015 [US2] Integrar DELETE en `config/routes.php` con `src/Finance/Http/PaymentCorrectionHandler.php` y el middleware existente de sesión y CSRF. Validar `If-Match` y el motivo opcional. Conservar 404 uniforme y traducir la repetición del alta eliminada al 409 específico en `src/Finance/Http/IncomeWriteHandler.php`. Ejecutar T012.
- [X] T016 [US2] Incorporar el diálogo accesible `frontend/src/components/PaymentCorrections.tsx` en `frontend/src/components/IncomePage.tsx` y usar `frontend/src/lib/income-api.ts` para confirmación y reintento. Tras el 204, abrir el proyecto con datos nuevos. Al cancelar o fallar, preservar el contexto. Ejecutar T013.

## Fase 5 · Validación integral

- [X] T017 Ejecutar `specs/004-correct-payments/quickstart.md`, las regresiones de PHP y Playwright y la validación de migración. Registrar resultados en `docs/validation/payment-corrections.md`, incluidos privacidad de la PWA, saldo, reporte mensual y reintentos concurrentes.

## Dependencias y trabajo en paralelo

Preparación → Fundamentos → US1 → US2 → Validación integral.

- T002 y T003 se pueden preparar en paralelo. T004 precede a la integración de persistencia y lecturas.
- En US1, T007 y T008 se pueden preparar en paralelo. Las dos se ejecutan tras incorporar sus respectivos puntos de entrada.
- En US2, T012 y T013 se pueden preparar en paralelo. La validación de la carrera edición–eliminación usa la edición de US1.
- Las pruebas que escriben en PostgreSQL deben usar datos de prueba independientes.

## Estrategia de entrega

US1 es el primer incremento verificable. El MVP de corrección completo abarca US1 y US2, ambas P1, con validación integral.

## Fase 6 · Pruebas y fundamentos del resumen de pagos

- [X] T018 [P] Preparar en `tests/Integration/AccountAccessTest.php` una lectura de proyecto con cobros activos en MXN y otra moneda histórica, un cobro eliminado y un cargo separado. Verificar el conteo, el saldo y la lista en MXN, el 404 uniforme para otra cuenta y las cabeceras privadas.
- [X] T019 [P] Preparar en `frontend/tests/project-payment-summary.spec.ts` pruebas de la función pura para cero, singular, plural, cantidad alcanzada, pagos adicionales y cantidad acordada nula. Verificar conteo vigente y proyección de un alta nueva.
- [X] T020 Ajustar `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` para contar todos los cobros activos del proyecto y titular, incluidas monedas históricas. Conservar el filtro en MXN para el saldo y la lista monetaria. Mantener el contrato de GET y ejecutar T018.
- [X] T021 Tipar los campos existentes en `frontend/src/lib/income-api.ts` y calcular pendientes, adicionales y proyección en `frontend/src/lib/project-payment-summary.ts`. Ejecutar T019.
- [X] T022 Crear el bloque accesible `frontend/src/components/ProjectPaymentSummary.tsx` y darle estilos en `frontend/src/styles/theme.css`. Mostrar el saldo en MXN por separado y distinguir conteo vigente, proyección condicional, carga y error con reintento. Aplicar los textos y la pluralización de la especificación.

## Fase 7 · US3 · Consultar el resumen al registrar o editar un cobro

**Objetivo.** Incorporar el [resumen especificado](spec.md#requisitos-y-alcance) en ambos formularios. US3 identifica este incremento en el listado de tareas.

**Prueba independiente.** Con dos cobros de cuatro acordados, verificar que el alta muestra dos recibidos, dos pendientes y la proyección de tres recibidos con uno pendiente. En edición, verificar el mismo conteo vigente y el aviso de que editar conserva la cantidad. Repetir un guardado cuya respuesta se perdió y comprobar que el conteo coincide con los cobros activos.

- [X] T023 [P] [US3] Preparar en `frontend/tests/income.spec.ts` el recorrido de alta con conteo, saldo separado, proyección, fallo de guardado y reintento tras una respuesta perdida. Cubrir también cantidad alcanzada, pago adicional y proyecto histórico sin cantidad acordada.
- [X] T024 [P] [US3] Preparar en `frontend/tests/payment-corrections.spec.ts` el recorrido de edición con aviso y sin proyección, error de lectura recuperable sin perder campos ni bloquear el guardado, revalidación tras conflicto y descarte de una respuesta recibida después de cerrar el editor.
- [X] T025 [US3] Integrar el resumen arriba del importe en `frontend/src/components/PaymentForm.tsx` usando el detalle del proyecto seleccionado. Reconsultar al confirmar, repetir o perder la respuesta de un alta. Conservar la clave y los campos ante resultados inciertos, descartar lecturas obsoletas y permitir reintentar únicamente el GET. Ejecutar T023.
- [X] T026 [US3] Integrar el resumen arriba del importe en `frontend/src/components/PaymentCorrections.tsx` con el proyecto del cobro autenticado. Mostrar el aviso de conteo constante y revalidar tras guardar o recibir un conflicto. Mantener los borradores ante fallos de lectura y descartar respuestas tardías al cerrar o perder acceso. Revisar la integración con `frontend/src/components/IncomePage.tsx` y `frontend/src/lib/private-session.ts`. Ejecutar T024.

## Fase 8 · Validación del incremento

- [X] T027 Ejecutar la sección del resumen en `specs/004-correct-payments/quickstart.md` con PostgreSQL independiente y Playwright bajo HTTPS. Verificar escritorio y 360 px, exclusión de cargos y bajas, privacidad sin conexión y regresiones de alta, edición, eliminación y saldos. Registrar resultados en `docs/validation/payment-corrections.md`.

## Dependencias y entrega del resumen

- T018 y T019 pueden prepararse en paralelo. T020 depende de T018 y puede avanzar en paralelo con T021 una vez preparada T019. T022 usa el tipo y el cálculo de T021.
- T023 y T024 pueden prepararse en paralelo. T025 y T026 pueden implementarse en paralelo cuando T020 y T022 estén listos y sus pruebas estén preparadas. T027 requiere los dos recorridos.
- El MVP del resumen abarca alta y edición. Validar primero el alta y luego la edición con el bloque compartido antes de cerrar el incremento.
