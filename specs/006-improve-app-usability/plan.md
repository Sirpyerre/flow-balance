# Plan técnico · Usabilidad de Flow Balance

## Resumen

Unificar navegación, temas y controles. Entregar primero las mejoras de lectura y selección de periodo, luego los formularios y las escrituras, y cerrar con la adaptación completa de la PWA.

## Contexto técnico

Astro para páginas y layout, islas de React para formularios, PHP 8.4 con Mezzio, PostgreSQL, Herd y Docker Compose. Reutilizar tokens en `frontend/src/styles/theme.css`, sesiones y funciones de cálculo existentes. Validar con Astro build, PHPUnit sobre PostgreSQL independiente y Playwright sobre HTTPS. El incremento 3 requiere una migración aditiva de Phinx para registrar el motivo de los ajustes de acuerdo que dejan excedente. Los demás incrementos reutilizan el esquema vigente.

## Revisión constitucional

La especificación y el plan están en español. Identificadores en inglés e interfaz es-MX. Los totales y la vista previa usan reglas decimales fuera de componentes visuales. Los reportes conservan trazabilidad, el guardado de cobros sigue protegido contra duplicados y las lecturas y escrituras mantienen pertenencia, CSRF y política sin caché privada. Cada incremento se prueba a 360 y 1080 px, incluido el recorrido de captura.

## Estructura del proyecto

- [Especificación](spec.md), [decisiones](research.md), [modelo](data-model.md), [contratos](contracts/usability.md), [validación](quickstart.md) y [referencias visuales](references/index.md).
- Astro en `frontend/src/layouts/` y `frontend/src/pages/`. Compartir la navegación del tablero con las pantallas privadas de clientes, proyectos y cobros. Extraer el selector de periodo que hoy vive en `dashboard.astro`.
- React en `frontend/src/components/`. Reusar `IncomePage`, `IncomeSetupForm`, `PaymentForm`, `PaymentCorrections`, `PaymentChargeForm` y `ProjectPaymentSummary` para conservar el flujo.
- Mezzio en `config/routes.php`, dominio en `src/Finance/Application/`, consultas en `src/Finance/Infrastructure/`. Pruebas en `tests/Integration/` y `frontend/tests/`.

## Secuencia de implementación

| Incremento | Dependencias | Entrega y condición de salida |
| --- | --- | --- |
| 1 · US1, US2 | Ninguna | Layout común, navegación adaptativa, botones y temas. Probar persistencia del tema, contraste, foco y espacio de la barra inferior. |
| 2 · US3 | 1 | Selector compartido con borrador y aplicación. Extender historial con filtro y resumen de todo el conjunto, manteniendo cursor. Probar meses históricos, moneda mixta, cancelación y paginación. |
| 3 · US4 | 1 | Alta de cliente con contexto, alta y edición de proyecto con panel de apoyo. Ampliar PATCH para campos del acuerdo y usar una regla compartida de validación. Agregar la migración del historial de ajustes de acuerdo antes de habilitar PATCH. Probar correcciones del alta, reasignación, motivo obligatorio, persistencia atómica y cancelación. |
| 4 · US5 | 1 y resumen de proyecto existente | Registro con selección de proyecto y detalle de cobro, edición y cargos en el mismo patrón. Mantener el proyecto fijo en edición y limitar el formulario de cargos a Comisión e Impuesto. Reusar la proyección existente y extenderla para calcular el saldo al sustituir el abono editado. Probar MXN, USD, idempotencia, concurrencia y sesión. |
| 5 · US6 | 1 a 4 | Completar los listados privados de clientes y proyectos y ajustar la composición móvil de tablero, historial, listados y formularios. Probar zoom, teclado, área segura, reportes y PWA. |

Los incrementos 2 y 3 pueden avanzar en paralelo tras el layout común. La entrada a cobros depende de una selección de proyecto accesible, que puede reutilizar el selector del tablero desde el incremento 4. Usar la captura 2 para edición de proyecto y la 3 como patrón de edición de cobro, con el contexto del registro vigente.

## Ajuste de monto acordado

1. Compartir la validación de importe y la proyección decimal de saldo con el detalle de proyecto. Añadir una vista previa privada del acuerdo que muestre el abonado computable, el saldo y el excedente proyectado antes de guardar. Una conversión pendiente conserva el indicador provisional.
2. En PATCH, validar y bloquear el proyecto propio con el mismo orden de bloqueo que los flujos de cobros. Volver a leer el acuerdo y los abonos computables dentro de la transacción. Si el cambio reduce el monto y el resultado es menor al abonado, exigir y validar la razón. Si el acuerdo histórico carecía de monto, tratar el primer importe como captura del acuerdo y aplicar las validaciones de alta. Cambiar nombre, origen, cantidad de pagos o entrega conserva las reglas vigentes. Conservar la restricción de reasignación cuando el proyecto tiene cobros.
3. Guardar el nuevo acuerdo y el motivo en una sola transacción. Crear la tabla `project_agreement_revisions` para mantener cada ajuste efectivo en un registro propio del proyecto con el monto anterior, el nuevo y el excedente calculado al guardar. Un reintento inmediato con el monto ya guardado conserva una sola revisión. Una validación fallida deja intactos el acuerdo y el historial. La reversión de la migración se bloquea mientras haya ajustes registrados y la guía explica cómo conservarlos antes de retirar la tabla.

## Revisión posterior al diseño

Las ampliaciones son aditivas a los contratos existentes. La navegación y el tema se aplican sin datos privados y desde el primer renderizado. Las etiquetas de estado complementan el color. Las validaciones de pertenencia, importes, conversión y reintentos permanecen en sus fronteras actuales. Las cuentas y sesiones de prueba son independientes de los datos de desarrollo.
