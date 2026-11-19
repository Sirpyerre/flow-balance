# Modelo de datos propuesto

- `payments` agrega `version` y `deleted_at`. Un cobro activo tiene `deleted_at` nulo. Se conservan su titular, su proyecto y su clave y huella de alta.
- `payment_revisions` conserva cobro y cuenta, autor, fecha, acción, versión y valores previos. Cada edición del flujo nuevo registra una clave única por cobro y su huella, vinculada a la versión original y al contenido normalizado, además de la versión resultante. Una revisión de eliminación admite un motivo opcional. Los decimales se guardan como cadenas exactas y la relación con el cobro preserva la pertenencia a la cuenta.
- `payment_charges` sigue asociado al cobro original. La baja del padre lo excluye de vistas operativas. El cambio de fecha del cobro conserva la fecha del cargo.

## Resumen de pagos del proyecto

- `paymentCount` es un entero no negativo. Incluye una vez cada cobro activo del proyecto y de la cuenta.
- `agreedPaymentCount` es un entero positivo o nulo para proyectos históricos.
- `outstandingBalance` es una cadena decimal en MXN o nulo. Se presenta como saldo monetario independiente.
- Con cantidad acordada, el número pendiente es el máximo entre cero y la cantidad acordada menos el conteo. El número adicional es el máximo entre cero y el conteo menos la cantidad acordada. Si falta lo acordado, ambas cantidades son indeterminadas.
- La proyección de alta usa el conteo vigente más uno. El conteo proyectado nunca se persiste y el modo edición muestra solo el conteo vigente.
