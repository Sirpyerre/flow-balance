# Modelo de datos de Registro de ingresos

| Entidad | Campos y relaciones |
| --- | --- |
| `Client` | Identificador, titular y nombre recortado de 1 a 100 caracteres. Un cliente tiene cero o más proyectos |
| `Project` | Identificador, titular, `clientId`, nombre, `agreedAmount`, `agreedCurrency`, `agreedPaymentCount` y `acquisitionSource`. Un proyecto tiene cero o más pagos. El nuevo monto se registra positivo en MXN y los proyectos históricos pueden tener estos datos nulos |
| `Payment` | Identificador, titular, `projectId`, `paidOn`, `currency`, `clientPaidAmount`, `platformFee`, `withheldTaxAmount`, `sourceType`, `sourceName`, `excessReason`, clave idempotente y huella de la operación original |
| `PaymentCharge` | Identificador, titular, `paymentId`, `chargeType`, `paidOn`, `amount`, `currency`, clave idempotente y huella original |
| Detalle del proyecto | `paymentCount` y `clientPaidTotal` derivados de sus pagos propios. Un proyecto sin pagos presenta cero y MXN 0.00. Compara el conteo real con `agreedPaymentCount` sin limitarlo |
| Reporte mensual | Agregado de importe abonado por titular, MXN y fecha de pago en intervalo semiabierto. Cada fila contiene el identificador del cobro para abrir su detalle |

- `sourceType` admite `direct_client`, `platform` y `other`. `sourceName` es obligatorio y recortado de 1 a 100 caracteres para plataforma y otra fuente, y nulo para cliente directo. Los cobros históricos pueden conservar fuente nula. Para un pago directo, el cliente se obtiene del proyecto.
- `receivedAmount` es un valor derivado. Resta las deducciones al abono con exactitud y admite cero. Deducciones no negativas, abono positivo y escala máxima de dos decimales. Rechazar entradas con precisión excesiva antes de persistirlas.
- Mantener las claves foráneas compuestas que protegen la cuenta. El guardado de un cobro no cambia el monto acordado ni el estado de entrega. El estado de entrega sigue independiente y su gestión queda fuera de este incremento.
- Las migraciones nuevas agregan campos y restricciones. Conservar importes y huellas existentes y asignar deducciones cero a pagos previos. La reversión bloquea el descarte de valores capturados y requiere preservarlos en un respaldo verificable con procedimiento de restauración.
- El endpoint heredado de edición de importe aplica la misma validación de deducciones sobre el registro actualizado, con comprobación atómica. El PATCH heredado rechaza incrementos de importe que provoquen o aumenten un excedente y conserva la razón original. La asociación cliente-proyecto puede cambiar mientras el proyecto no tiene pagos. El registro y el PATCH de importes y la reasignación de cliente bloquean primero el proyecto. El PATCH relee y bloquea el cobro después. El total se consulta luego de obtener el bloqueo.
- Normalizar la clave idempotente por operación y cuenta. Conservar el contrato histórico de huellas para llamadas existentes. Comparar siempre contra la huella original, aunque una edición posterior cambie el importe.

- `acquisitionSource` es texto recortado de 1 a 100 caracteres y `agreedPaymentCount` es un entero positivo en el alta. Mostrar datos históricos ausentes como «Sin registrar». MXN es obligatorio para nuevos montos acordados, cobros y cargos.
- `excessReason` admite de 1 a 250 caracteres recortados. Se exige al guardar un cobro si el total resultante supera el monto acordado. Se calcula `excessAmount` en la moneda del proyecto a partir del total resultante y se omite la comparación si falta el monto acordado. Un excedente exacto de cero queda exento. Conservar la razón junto al cobro y en su huella normalizada. Los cobros anteriores conservan razón nula.
- Cada `PaymentCharge` conserva `commission` o `tax`, una fecha efectiva válida hasta hoy, un importe positivo con precisión de MXN y moneda MXN. Una restricción única de `payments(user_id, id)` respalda la referencia compuesta `payment_charges(user_id, payment_id)` con borrado restringido. Una restricción única por cuenta y clave protege los reintentos de cargos. Los cobros históricos empiezan sin cargos separados.
- La reversión de las migraciones nuevas debe proteger también el origen y número acordado, las razones y los cargos separados. Conservar un respaldo restaurable y verificar recuperación de las referencias y las huellas.
