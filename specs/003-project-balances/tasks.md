# Tareas de Saldos pendientes por proyecto

**Entrada** — [Especificación](spec.md), [plan](plan.md), [decisiones](research.md), [modelo](data-model.md), [contrato](contracts/project-balances.md) y [guía de validación](quickstart.md).

## Criterios de revisión de cada implementación

- Mantener aritmética decimal exacta, MXN explícito y dos decimales. Distinguir acuerdo, abonos, saldo, excedente y dinero recibido.
- Compartir la regla entre detalle y reporte, fuera de Mezzio, Astro y React. Conservar la idempotencia y la trazabilidad hasta los cobros.
- Revisar autorización por cuenta, sesión, exclusión de caché y presentación a 360 px. Usar identificadores y comentarios en inglés y textos de interfaz en español de México.

## Fase 1 · Preparación

- [X] T001 Verificar PHP, PostgreSQL de pruebas, migraciones existentes y origen HTTPS con `phpunit.xml`, `frontend/playwright.config.ts` y `specs/003-project-balances/quickstart.md`. Ejecutar las suites existentes como línea base y consultar en modo de solo lectura cuántos abonos MXN difieren de `round(client_paid_amount, 2)`.

## Fase 2 · Fundamentos

- [X] T002 Escribir en `tests/Unit/ProjectBalanceTest.php` casos de saldo inicial y parcial, liquidación exacta de 0.30 con 0.10 y 0.20, excedente, acuerdo nulo y total acumulado mayor que el límite de un abono. Verificar la resta con un total normalizado de MXN 0.04.
- [X] T003 Implementar la regla compartida en `src/Finance/Application/ProjectBalance.php`. Revisar `src/Finance/Application/PaymentAmounts.php` y reutilizar operaciones compatibles con acumulados sin límite de un solo abono. Comparar y restar decimales sin float ni desbordamiento de enteros nativos. Devolver saldo, excedente y estado del contrato. Ejecutar T002.

## Fase 3 · US1 · Consultar el saldo en el detalle (P1)

**Objetivo.** Mostrar el saldo vigente y su estado junto al monto acordado, al total abonado y a los cobros.

**Prueba independiente.** En un proyecto de MXN 5,000.00, consultar el saldo inicial, registrar un abono de MXN 3,000.00 con MXN 200.00 en deducciones y agregar un cargo separado. Verificar MXN 2,000.00 pendientes. Completar el acuerdo, registrar un excedente y consultar un proyecto histórico sin acuerdo.

- [X] T004 [P] [US1] Preparar en `tests/Integration/AccountAccessTest.php` pruebas del GET de proyecto con los cuatro estados, abonos trazables, deducciones y cargos sin efecto sobre el saldo, reintento idempotente y equivalencia entre proyecto ajeno e inexistente. Probar dos abonos históricos de MXN 0.015 y comprobar que el desglose suma MXN 0.04. Con dos conexiones, verificar que una escritura concurrente no desalinee los importes y la lista de abonos.
- [X] T005 [P] [US1] Preparar en `frontend/tests/project-balances.spec.ts` el flujo de detalle a 360 px. Verificar etiquetas, dos decimales, importe nulo presentado como «Saldo por determinar», excedente por separado y acceso al detalle de un abono.
- [X] T006 [US1] Ajustar `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` para que acuerdo, suma y lista de cobros MXN propios provengan de una sola sentencia SQL. Sumar los mismos importes por abono redondeados que se muestran en el desglose. Aplicar T003 y conservar las claves existentes al ampliar la respuesta de `src/Finance/Http/OwnedResourceHandler.php`. Ejecutar T004.
- [X] T007 [US1] Extender los tipos y textos de estado en `frontend/src/lib/income-api.ts` e integrar el saldo en `frontend/src/components/IncomePage.tsx`. Mostrar excedente separado cuando corresponda, conservar los enlaces a cobros y ejecutar T005.

## Fase 4 · US2 · Revisar saldos de mi cuenta (P1)

**Objetivo.** Consultar saldos desde el tablero, con «Pendientes» por defecto y «Todos» como alternativa.

**Prueba independiente.** Con dos cuentas y proyectos en los cuatro estados, confirmar el aislamiento, el filtro y los dos estados vacíos. Para un acuerdo de MXN 5,000.00 con MXN 3,000.00 abonados en noviembre y MXN 800.00 en diciembre, comprobar que el saldo permanece en MXN 1,200.00 al cambiar el mes.

- [X] T008 [P] [US2] Preparar en `tests/Integration/AccountAccessTest.php` pruebas del contrato GET con dos cuentas, lista vacía, orden por nombre e identificador, proyecto sin abonos, cuatro estados, sesión inválida, no-store y paridad con el detalle. Verificar que los totales mensuales se mantienen separados.
- [X] T009 [P] [US2] Preparar en `frontend/tests/project-balances.spec.ts` el filtro inicial «Pendientes», opción «Todos», los dos estados vacíos, enlaces a proyecto, cambio de mes, lectura a 360 px y ausencia de datos privados al perder conexión y recargar. Simular una respuesta tardía durante la limpieza, un error con reintento y una carga sin mensajes de vacío. Verificar que el reporte mensual conserva su función si falla la consulta de saldos.
- [X] T010 [US2] Crear `src/Finance/Infrastructure/PostgresProjectBalanceReportReader.php` con una consulta agrupada por proyecto. Usar unión izquierda a abonos del mismo titular y en MXN, incluir proyectos sin cobros y sumar los importes con el mismo redondeo por abono del detalle. Pasar cada fila por T003. Ordenar por nombre e identificador.
- [X] T011 [US2] Exponer el GET privado en `config/routes.php` mediante `src/Finance/Http/ProjectBalanceReportHandler.php`, con identidad de sesión, respuesta no-store y contrato de `specs/003-project-balances/contracts/project-balances.md`. Ejecutar T008.
- [X] T012 [US2] Integrar la sección de saldo vigente en `frontend/src/pages/dashboard.astro` y los tipos en `frontend/src/lib/income-api.ts`. Filtrar por estado en memoria, distinguir carga, error con «Reintentar» y ambos estados vacíos tras el éxito, y enlazar al detalle. Cancelar la solicitud al limpiar u ocultar el tablero y verificar su vigencia antes de renderizar. Aplicar el ciclo de carga y limpieza de `frontend/src/lib/private-session.ts` y mantener el selector de mes limitado a los abonos. Ejecutar T009.

## Fase 5 · Validación integral

- [X] T013 Ejecutar la guía de `specs/003-project-balances/quickstart.md`, correr PHPUnit y Playwright bajo HTTPS contra la base independiente, y registrar evidencias y pendientes en `docs/validation/project-balances.md`. Verificar regresiones de ingreso, reporte mensual, autorización y privacidad de la PWA.

## Dependencias y paralelismo

```text
Preparación → Fundamentos → US1 → US2 → Validación integral
```

- US1 requiere la regla compartida. US2 usa esa regla y el detalle de US1 para explicar cada saldo.
- T004 y T005 pueden escribirse en paralelo a partir del contrato. Las pruebas de ambas historias se consolidaron por suite para reutilizar sus datos y recorridos. T006 precede a la validación de la UI en T007.
- T008 y T009 pueden escribirse en paralelo a partir del contrato. T010 precede a T011 y la integración de T012 se verifica contra el endpoint disponible.

## Estrategia de implementación

Entregar US1 como primer incremento verificable. El MVP de la fase queda completo con US1 y US2, ambas P1, más la validación integral.

| Historia | Requisitos y aceptación |
| --- | --- |
| US1 | FR-001–FR-005, FR-007 y SC-001 |
| US2 | FR-004, FR-006–FR-009 y SC-002–SC-003 |
