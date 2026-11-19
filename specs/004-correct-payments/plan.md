# Plan técnico de Corrección de cobros

**Entrada** — [Especificación](spec.md) · [Investigación](research.md) · [Modelo](data-model.md) · [Contrato](contracts/payment-corrections.md) · [Validación](quickstart.md)

## Diseño y secuencia

1. **Fundamentos.** Crear migraciones Phinx aditivas para versión del cobro, marca de eliminación e historial. Inicializar los registros históricos como activos y proteger la reversión cuando existan correcciones. Mantener la unicidad de la clave de creación y la huella original tras editar o eliminar.
2. **Lecturas y reglas compartidas.** Aplicar un criterio explícito de cobro activo al detalle, tablero, reporte mensual, saldo, avance, cálculo de excedente y altas de cargos. Las búsquedas de idempotencia y de auditoría incluyen eliminados. Revisar las consultas de proyectos que impiden reasignar clientes con historial. Conservar la regla de saldos aprobada y aplicar su normalización al comparar el excedente anterior y el proyectado de la edición.
3. **US1.** Extender el PATCH de Mezzio y reutilizar las validaciones de fecha, fuente y deducciones de `PaymentInput` y `PaymentAmounts`. Bloquear primero el proyecto y después el cobro, verificar versión y propiedad tras el bloqueo, y guardar el cambio y su historial en una transacción. Conservar los llamadores del PATCH mínimo existente y hacer que sus cambios incrementen la versión y queden auditados. Incorporar el formulario en Astro y React. Mantener una clave por intento y conservarla junto con la versión original y el contenido al reintentar. Resolver los reintentos bajo el bloqueo del cobro y antes de rechazar una versión anterior. Guardar la clave, la huella y la versión resultante de la corrección en la misma transacción que el cambio.
4. **US2.** Agregar DELETE protegido y confirmación accesible. Registrar la baja lógica, el historial y el motivo opcional en una transacción. Al eliminar, volver al proyecto y usar lecturas nuevas. Mantener la huella de alta como reserva permanente. Verificar que los cargos conservados no puedan consultarse ni agregarse desde un cobro eliminado. Coordinar alta de cargos y eliminación con el mismo bloqueo del padre, y versionar la incorporación de cargos para detectar confirmaciones desactualizadas.
5. **Validación.** Ejecutar pruebas unitarias, PostgreSQL aislado y Playwright bajo HTTPS. Verificar ambas historias por separado, incluidos reintentos, edición y eliminación concurrentes, aislamiento, cortes de mes, privacidad de la PWA y regresión del PATCH mínimo.

## Decisiones

- **Baja lógica.** Conserva historial, dependencias y clave de alta. Los lectores de actividad excluyen el cobro y sus cargos.
- **Control de concurrencia.** Una versión evita sobreescrituras y eliminaciones desde pantallas desactualizadas. Un reintento de eliminación ya confirmada conserva el resultado.
- **Trazabilidad.** Se preservan valores exactos y se deriva el saldo en cada lectura. El historial de esta fase es interno.
- **Interfaz.** Mantener las acciones y el formulario en el detalle del cobro, con español de México, estilos de Flow Balance y protección de sesión existente.

## Revisión constitucional

La regla de negocio se prueba fuera de Mezzio y React. Los informes conservan trazabilidad y exactitud decimal, y la fecha de abono gobierna el periodo. La corrección no altera el estado de entrega. Los cambios usan identificadores en inglés y se validan en escritorio y móvil.

## Incremento · Resumen de pagos del proyecto

### Contexto técnico y revisión constitucional

Astro monta los formularios React. Mezzio y PostgreSQL ya exponen el conteo de cobros activos en el detalle protegido del proyecto. El conteo actual de la consulta está restringido a MXN. Separar el conteo de cobros activos del agregado monetario para que el primero incluya cualquier moneda histórica sin modificar la suma en MXN. El incremento reutiliza estos componentes sin migraciones, dependencias nuevas ni rutas adicionales. El saldo se toma del cálculo decimal existente y se muestra separado. La proyección es informativa y no altera la captura, su idempotencia ni los estados financieros.

### Orden de implementación

1. Definir un tipo de resumen a partir de `paymentCount`, `agreedPaymentCount` y `outstandingBalance`. Extraer una función pura para conteos pendientes y adicionales y cubrir los límites sin depender de React. Compartir la presentación y la pluralización entre alta y edición.
2. En `frontend/src/components/PaymentForm.tsx`, usar el detalle ya cargado para mostrar el conteo actual y la proyección de una alta nueva, arriba del importe. Si se confirma un alta o un reintento, consultar el conteo persistido sin sumar localmente. Si se pierde la respuesta, conservar la clave y los campos del formulario. Revalidar el resumen sin recrear el formulario y mantener la proyección etiquetada como condicional. Si la revalidación falla, indicar que el conteo no está disponible y ofrecer reintentar solo la lectura.
3. En `frontend/src/components/PaymentCorrections.tsx`, obtener el resumen al abrir el editor con el ID de proyecto del cobro autenticado. Usar el mismo bloque y el aviso de que la edición conserva la cantidad. Tras un guardado o un conflicto de versión, reutilizar la revalidación vigente del detalle. Cancelar lecturas y descartar respuestas tardías al cerrar el editor o perder acceso.
4. Cubrir los casos unitarios y la lectura de la API en integración. Ejecutar los recorridos de alta y edición a 360 px y en escritorio. Verificar que un error de lectura del resumen ofrece reintento y conserva los campos y la posibilidad de guardar. Si aún no hay datos verificados, mostrar carga o error sin sustituirlos por ceros.

### Fuentes y archivos

- `src/Finance/Infrastructure/PostgresOwnedResourceRepository.php` conserva la lectura por titular y excluye cobros eliminados. El resumen consume los campos existentes por medio de `frontend/src/lib/income-api.ts`.
- `frontend/src/components/PaymentForm.tsx` y `frontend/src/components/PaymentCorrections.tsx` comparten el bloque de resumen. `frontend/src/components/IncomePage.tsx` y `frontend/src/lib/private-session.ts` conservan el ciclo de privacidad.
- Ampliar los recorridos de `frontend/tests/income.spec.ts` y `frontend/tests/payment-corrections.spec.ts` y reutilizar las comprobaciones de pertenencia de `tests/Integration/AccountAccessTest.php`.

La revisión posterior al diseño confirma la exactitud, la separación de conceptos financieros, el alcance por cuenta y la comprobación independiente. Las 17 tareas cerradas documentan el incremento de corrección anterior. Las tareas del resumen se añaden en el siguiente paso.
