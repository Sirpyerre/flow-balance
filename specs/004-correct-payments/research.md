# Decisiones del resumen de pagos

| Decisión | Motivo | Alternativa considerada |
| --- | --- | --- |
| Reutilizar el GET de proyecto | Ya filtra por titular y devuelve conteo, cantidad acordada y saldo. Se amplía el conteo a cualquier cobro activo histórico y se mantiene el agregado monetario en MXN. Alta ya lo consulta. Edición puede consultarlo al abrir el formulario sin duplicar reglas en la API. | Crear un endpoint de resumen o enriquecer todas las respuestas del cobro. Se reserva si la lectura existente resulta costosa. |
| Derivar la proyección en la interfaz con una función pura | La proyección equivale a un alta nueva sobre el conteo leído. El dato vigente siempre proviene del servidor. | Guardar un contador, o consultar al servidor cada vez que cambia el importe. |
| Reconsultar el proyecto tras una escritura de resultado incierto o confirmado | Un reintento puede devolver un cobro ya existente. Una lectura vigente evita sumar dos veces y recoge altas o bajas de otra pestaña. | Incrementar el contador local tras una respuesta de guardado. |
| Usar texto explícito para actual y proyección | «2 de 4 pagos recibidos» es legible y una proyección etiquetada separa el dato vigente del posible resultado. | Mostrar solo «2/4» o una barra de progreso. |
