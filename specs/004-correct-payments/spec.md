# Corrección de cobros capturados por error

**Estado** — Propuesta para revisión

Una persona freelance necesita corregir un cobro erróneo o retirarlo sin perder la trazabilidad de sus reportes.

## Aclaraciones

### Sesión 2026-11-19

- Q: ¿Se debe pedir un motivo al eliminar un cobro? → A: La confirmación permite escribir un motivo opcional que se guarda en el historial interno.

- Q: Si una edición se guardó pero se perdió la respuesta, ¿qué debe pasar al reintentar? → A: Reconocer el mismo intento y confirmar el guardado si el cobro sigue en la versión resultante. Si cambió después, pedir revisar la versión vigente.

- Q: ¿Qué debe mostrar el resumen antes de guardar un cobro nuevo? → A: Mostrar el conteo actual y una proyección separada de cuántos pagos habrá si se registra un cobro nuevo.

## US1 · Editar un cobro propio (P1)

1. **Dado** un proyecto de MXN 5,000.00 con un abono de MXN 3,000.00 en noviembre, **cuando** corrijo ese abono a MXN 2,500.00 y lo paso a diciembre, **entonces** noviembre pierde MXN 3,000.00, diciembre agrega MXN 2,500.00 y quedan MXN 2,500.00 pendientes.
2. Desde el detalle se pueden corregir importe, fecha, fuente, comisión e impuestos descontados. Se conserva la asociación con el proyecto y la moneda sigue siendo MXN. Si la corrección crea o aumenta el excedente vigente, se exige una razón. Deducciones superiores al abono o una fecha futura impiden guardar. El formulario conserva los valores escritos.
3. Un cargo separado se conserva con su importe y fecha al editar el cobro. Si otra operación cambió el cobro desde que se abrió el formulario, se avisa y se permite revisar la versión vigente antes de volver a guardar.

4. Si se pierde la respuesta de un guardado, reintentar la misma edición confirma el resultado cuando el cobro sigue en la versión resultante y conserva una única entrada de historial. Si otra operación cambió la versión, se pide revisar el estado vigente.

## US2 · Eliminar un cobro propio (P1)

1. Desde el detalle, «Eliminar cobro» pide confirmación e identifica el importe, la fecha y los cargos separados asociados. La confirmación permite escribir un motivo opcional. Si se proporciona, queda en el historial interno. Omitirlo permite confirmar. Cancelar conserva los datos.
2. Al confirmar, el cobro y sus cargos asociados dejan de aparecer en las vistas activas. Se recalculan el total abonado, el avance, el saldo y el reporte mensual. Si era el único abono de MXN 3,000.00 de un acuerdo de MXN 5,000.00, el reporte del mes queda vacío y el saldo vuelve a MXN 5,000.00.
3. El sistema conserva el registro y sus cargos para trazabilidad. Reintentar la eliminación produce el mismo resultado y reenviar la solicitud original de alta no reactiva ni duplica el cobro.
4. Una solicitud de otra cuenta recibe la misma respuesta que un recurso inexistente. Una versión desactualizada no puede eliminar un cobro que cambió desde que se abrió la pantalla.

## Requisitos y alcance

- Las operaciones requieren sesión, pertenencia a la cuenta y confirmación explícita para eliminar. También se protege la privacidad sin conexión y se ofrecen estados de carga, error y reintento.
- La edición y la eliminación registran fecha, autor y valores anteriores. El historial se conserva internamente. La interfaz de restauración, la eliminación definitiva, el traslado entre proyectos y la edición individual de cargos quedan fuera de esta propuesta.
- Las validaciones monetarias, el redondeo aprobado para saldos y las definiciones de abonado y recibido se conservan. Los informes vigentes reflejan el estado corregido en la próxima consulta, incluso si cambió un mes anterior.
- Al registrar o editar un cobro, mostrar «Pagos del proyecto» arriba del importe. Contar una vez cada cobro activo guardado del proyecto, incluido el que se esté editando. Los cargos separados y los cobros eliminados quedan fuera del conteo. Mantener por separado el saldo pendiente en MXN.
- Con dos cobros activos de cuatro acordados, mostrar «2 de 4 pagos recibidos» y «Faltan 2 pagos». En el alta, mostrar debajo «Al guardar este cobro: 3 de 4 pagos recibidos» y «Faltará 1 pago». Identificar la proyección como condicional a un alta nueva. Si falla el guardado o se repite un alta ya confirmada, el conteo actual refleja únicamente los cobros activos persistidos.
- Al editar, mostrar el conteo actual y «Esta edición no cambia el número de pagos». Si se alcanza la cantidad acordada, mostrar «4 de 4 pagos recibidos» y «Cantidad acordada de pagos alcanzada». Si se supera, mostrar el conteo real, por ejemplo «5 de 4 pagos recibidos» y «1 pago adicional». La vista previa del alta aplica las mismas reglas al conteo proyectado.
- Si falta la cantidad acordada, mostrar «2 pagos recibidos» y «Cantidad acordada sin registrar». En el alta, anticipar solo el nuevo conteo, por ejemplo «Al guardar este cobro: 3 pagos recibidos». Mostrar singular o plural según corresponda.

- En escritorio y a 360 px se puede editar o confirmar la eliminación desde el detalle sin desplazamiento horizontal. Si falla el guardado, se muestran acciones para revisar o reintentar sin darlo por confirmado.
