# Investigación y decisiones de Registro de ingresos

## Flujo de alta y significado de fuente

**Decisión.** Dar de alta el cliente y después el proyecto en operaciones confirmadas por separado. Ofrecer selección de cliente existente y retorno al formulario de proyecto tras crear un cliente. Guardar el origen y la cantidad acordada en el proyecto. Capturar la fuente en cada pago y derivar el avance de los cobros registrados, incluso si la cantidad real supera la acordada.

**Motivo.** `CreateClient` y `CreateProject` ya aplican las reglas de nombre y pertenencia. Una fuente por pago permite combinar cliente directo, plataforma y otra fuente en un mismo proyecto.

**Alternativas consideradas.** Alta combinada, una fuente compartida para proyecto y cobro, y calendario de parcialidades. La captura por etapas y los dos datos de procedencia corresponden a las aclaraciones.

## Cálculo exacto y compatibilidad

**Decisión.** Extender la normalización de `ClientPaidAmount` a montos MXN con hasta 14 dígitos enteros y hasta dos decimales. Calcular en centavos enteros en PHP de 64 bits y convertir a cadenas decimales en las respuestas. Mantener `SUM` sobre `numeric` para agregados. Enviar la previsualización a una operación de cálculo sin escritura que comparte la misma regla con el guardado.

**Motivo.** La precisión existente cabe en enteros de 64 bits por pago. Un agregado puede exceder ese límite. La previsualización y la escritura deben mostrar el mismo recibido.

**Alternativas consideradas.** Coma flotante, una regla duplicada en el navegador y una dependencia adicional de aritmética decimal.

## Idempotencia y concurrencia

**Decisión.** Extender `RecordPayment` y conservar la unicidad por cuenta y clave. Validar pertenencia antes de resolver conflictos. Incluir fuente y deducciones normalizadas en la huella de la nueva operación. Conservar la fórmula histórica para los llamadores anteriores que omiten fuente y deducciones. Verificar conflictos por lectura posterior a un `INSERT … ON CONFLICT DO NOTHING` bajo `READ COMMITTED`.

**Motivo.** Un doble toque concurrente devuelve el mismo identificador. La comparación utiliza la huella original aunque el importe se actualice por el endpoint heredado. Preservar la fórmula anterior conserva los reintentos históricos. El orden de lectura se ajusta a la [visibilidad de PostgreSQL en Read Committed](https://www.postgresql.org/docs/18/transaction-iso.html).

**Alternativas consideradas.** Deduplicar por coincidencia de campos o por estado de la interfaz. Los dos enfoques afectan pagos legítimos o dejan carreras.

## Privacidad y trazabilidad

**Decisión.** Mantener páginas Astro estáticas con identificadores en la consulta y cargar los datos después de verificar sesión. Reutilizar consultas por cuenta, CSRF para operaciones POST y la política de red para respuestas privadas. Conservar la asociación histórica del cliente bloqueando la reasignación de proyectos con cobros registrados. Serializar reasignación y alta de pago mediante bloqueo de la misma fila de proyecto, con transacción y comprobación posterior al bloqueo.

**Motivo.** El cliente identifica la fuente de un pago directo. La política evita cambiar su procedencia y conserva la arquitectura existente.

**Alternativas consideradas.** Render dinámico en Astro o duplicar el cliente en los registros del pago. Los shells existentes y una regla explícita en la reasignación cubren este incremento.

## Excedente frente al monto acordado

**Decisión.** Previsualizar la diferencia con el total propio del proyecto. Al registrar un cobro, bloquear la fila del proyecto en la transacción y consultar el total en una sentencia posterior. Todas las escrituras que cambian ese total, incluida la edición heredada del importe, siguen el mismo orden. Resolver primero un reintento equivalente autorizado y validar el excedente solo para una operación nueva. El importe del cargo separado queda fuera de este cálculo.

**Motivo.** Dos cobros concurrentes pueden hacer que el segundo supere el acuerdo aunque ambas previsualizaciones hayan mostrado cero. La suma y resta del agregado usan `numeric` para preservar precisión. Si falta una razón, la UI muestra el excedente actualizado y conserva la captura. Si ya se capturó una razón válida, el guardado usa el total vigente y la confirmación muestra el excedente actualizado. Un reintento confirmado sigue devolviendo el cobro original cuando cambia el total. [Visibilidad de PostgreSQL en Read Committed](https://www.postgresql.org/docs/18/transaction-iso.html)

**Alternativas consideradas.** Validación exclusiva en el navegador y transacciones serializables para todo el flujo. El bloqueo por proyecto serializa las escrituras que comparten el mismo total.

## Cargos pagados por separado

**Decisión.** Registrar cada cargo en una tabla hija del cobro con fecha efectiva, tipo, importe y moneda. Usar una clave y huella idempotentes propias para esa operación y una clave foránea compuesta por cuenta y cobro.

**Motivo.** Un cobro admite varios cargos pagados en fechas distintas. Los cargos separados aparecen en el detalle con su fecha y quedan fuera del dinero recibido al cobrar y del reporte de abonos.

**Alternativas consideradas.** Añadir columnas por tipo de cargo al cobro o imputar gastos al abono. Ninguna opción preserva cada operación y su fecha.
