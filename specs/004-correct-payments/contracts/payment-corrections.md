# Contrato propuesto

| Operación | Resultado |
| --- | --- |
| GET del cobro | Devuelve el detalle activo y su versión. Para un cobro ajeno, eliminado o inexistente, devuelve 404. |
| PATCH del cobro | Recibe los campos editables y una versión esperada en `If-Match` y una cabecera `Idempotency-Key` en el flujo nuevo. Devuelve el detalle vigente con versión nueva en 200. Conserva el cuerpo mínimo admitido por el contrato anterior. |
| DELETE del cobro | Requiere versión esperada con `If-Match`. Recibe un objeto JSON. Enviar `{}` para omitir el motivo o incluir `reason` con hasta 250 caracteres para el historial interno. Devuelve 204 al eliminar y al repetir una eliminación propia ya confirmada. |
| POST de alta repetido | Si la clave corresponde a un cobro eliminado y la huella coincide, devuelve 409 con un código específico de cobro eliminado. No crea otro registro. |

Un PATCH repetido con la misma clave, versión original y contenido devuelve 200 si el cobro activo conserva la versión resultante, sin otra revisión. Si el cobro activo cambió después, devuelve 412. Reutilizar la clave con otra versión o contenido devuelve 409.

GET expone `version` como cadena decimal. En las escrituras del flujo nuevo, `If-Match` lleva esa versión entre comillas dobles. PATCH y DELETE requieren sesión y CSRF. Una versión desactualizada devuelve 412. DELETE sin versión devuelve 428. Los recursos ajenos e inexistentes reciben un 404 uniforme. Los errores de datos se devuelven en 422. Las respuestas privadas conservan la política sin caché.

## Resumen en los formularios

El cliente consume `GET /api/projects/{id}`. El detalle ya devuelve `paymentCount`, `agreedPaymentCount` y `outstandingBalance`. El conteo incluye cobros activos históricos en cualquier moneda y el saldo sigue en MXN. La lectura exige sesión, responde 404 uniforme fuera de la cuenta y conserva las cabeceras privadas sin caché.

Ambos formularios muestran el resumen encima del importe. El alta distingue la proyección condicional con «Al guardar este cobro». La edición muestra «Esta edición no cambia el número de pagos». El saldo aparece como dato aparte si está disponible. Al resolver un alta nueva o repetida, se sustituye el resumen únicamente con una lectura verificada del servidor.
