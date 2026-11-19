# Plan de implementación de Registro de ingresos

**Rama** `main` · **Fecha** 2026-11-18 · [Especificación](spec.md)

## Resumen

Habilitar el alta de clientes y proyectos con origen, monto acordado y número de pagos acordados. Capturar cobros en MXN con su propia fuente, fecha y deducciones, y mostrar el avance real de pagos. Cuando el total supere el monto acordado, mostrar el excedente y exigir una razón. Permitir registrar comisiones e impuestos pagados por separado desde el detalle del cobro. El reporte mensual conserva el total abonado por fecha de pago y permite abrir cada cobro.

## Contexto técnico

| Aspecto | Decisión |
| --- | --- |
| Lenguajes y dependencias | PHP 8.4, Laminas Mezzio, Phinx, Astro y TypeScript con React en los formularios interactivos |
| Persistencia | PostgreSQL del proyecto en Compose y base independiente de pruebas |
| Desarrollo | Herd sirve PHP y termina HTTPS. Astro y `/api` comparten origen de cara al navegador |
| Plataformas | Navegador de escritorio y PWA móvil. Se requiere conexión para consultar y guardar |
| Pruebas | PHPUnit para dominio e integración real con PostgreSQL, Playwright para flujos de escritorio y a 360 px |
| Alcance y objetivo | Proyectos, cobros y cargos en MXN. Un formulario por captura y totales con acceso al detalle |

## Revisión de la constitución

- **Antes de diseñar.** La especificación incluye altas, fuente y pagos verificables. La solución parte del stack existente y de las reglas de pertenencia, sesión y CSRF de la funcionalidad anterior.
- **Dinero.** Importes y moneda explícitos, precisión máxima de dos decimales, rechazo de precisión excesiva y cálculos exactos. Monto acordado, importe abonado, deducciones al cobrar, dinero recibido y cargos separados son conceptos distintos. Los cargos separados conservan su fecha. Las operaciones de cobro preservan el estado de entrega. El incremento cubre MXN.
- **Trazabilidad y consistencia.** Cada fila del reporte enlaza a un cobro propio. Cobros y cargos conservan una clave idempotente por cuenta y tipo de operación. El cálculo del excedente se revalida con el total vigente en la transacción. Se conserva la lectura de registros anteriores.
- **Dominio y revisión.** Cálculo y validación comprobables fuera de Mezzio, Astro y React. Identificadores, comentarios y PHPDoc en inglés, interfaz en español de México. Comentarios explican restricciones relevantes. Se reutilizan las reglas con el mismo significado y se mantienen responsabilidades explícitas.
- **Después del diseño.** Los contratos aplican sesión, CSRF, consultas por titular y exclusión de caché privada. El modelo verifica deducciones, cambios de mes, precisión, idempotencia y altas concurrentes que cruzan el monto acordado. El recorrido móvil queda validable mediante la guía. Las conversiones y el saldo pendiente quedan fuera del alcance aprobado para este incremento.

## Estructura del proyecto

| Ruta | Cambio previsto |
| --- | --- |
| `specs/002-register-income/` | Especificación, investigación, modelo, contratos y guía de validación |
| `src/Finance/` | Extender casos de uso y consultas existentes. Mantener las reglas monetarias en el dominio |
| `config/routes.php` | Incorporar altas y consultas protegidas en los recursos existentes |
| `db/migrations/` | Migraciones aditivas para datos del proyecto, ampliación de cobros y tabla de cargos separados |
| `frontend/src/pages/` | Completar alta de proyectos y agregar alta de cliente, detalle de proyecto, detalle de cobro y captura de cargos |
| `frontend/src/components/` | Islas de formularios donde haya interacción, con sesión y errores localizados |
| `tests/` y `frontend/tests/` | Pruebas del dominio, PostgreSQL y recorridos de escritorio y móvil |

## Orden previsto para las tareas

1. Extender persistencia y dominio con pruebas de compatibilidad.
2. Habilitar altas de cliente y proyecto con origen y cantidad acordada de pagos. Mostrar el avance desde el detalle.
3. Habilitar captura con fuente, deducciones y aviso de excedente recalculado al guardar. Habilitar el guardado idempotente en US2 antes de publicar la captura. Confirmar el excedente vigente con la razón válida y ampliar las pruebas de reintento y concurrencia en US3.
4. Habilitar cargos separados idempotentes y mostrarlos en el detalle. Integrar reporte y protecciones de la PWA. Validar migraciones, aislamiento y recorrido a 360 px.

Consultar [investigación](research.md), [modelo](data-model.md), [contratos](contracts/income.md) y [validación](quickstart.md).
