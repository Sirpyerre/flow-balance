# Guía de validación

1. En la base de pruebas, crea un acuerdo de MXN 5,000.00 con un cobro de MXN 3,000.00 en noviembre y un cargo separado. Corrige a MXN 2,500.00 en diciembre y verifica el saldo y ambos meses.
2. Prueba fecha futura, deducciones superiores al abono, excedente sin razón y edición desactualizada. Verifica que el cobro anterior se conserva y los mensajes permiten corregir el formulario.
3. Simula la pérdida de respuesta de un PATCH confirmado. Repite con la misma clave, versión original y contenido, y comprueba el 200 y una sola revisión. Modifica el cobro en otro intento y repite el primero. Verifica el 412 y que el cambio posterior se conserve.
4. Cancela una eliminación y verifica que no cambia nada. Confírmala y verifica el saldo, el conteo, el reporte y el acceso al detalle. Prueba bajas con y sin motivo. Verifica que, cuando se incluye, quede en el historial interno. Repite la eliminación y la solicitud original de alta.
5. Con dos conexiones, verifica edición contra eliminación y alta de cargo contra eliminación. Con otra cuenta, comprueba el rechazo uniforme y el estado intacto.
6. Ejecuta PHPUnit y Playwright sobre HTTPS con la base independiente, revisa el flujo a 360 px y comprueba la ausencia de datos privados sin conexión.

## Validar el resumen de pagos

Prepara la base y el origen HTTPS con la [guía local](../../docs/local-development.md). Ejecuta `APP_ENV=testing herd composer test` y `npm --prefix frontend run test:e2e` apuntando al origen de pruebas.

1. Crea un proyecto con cuatro pagos acordados y registra dos. Abre el alta y verifica «2 de 4 pagos recibidos», «Faltan 2 pagos» y la proyección de tres pagos con uno restante. Comprueba que el saldo vigente aparezca por separado.
2. Simula un error y luego una respuesta perdida tras confirmarse el alta. Reutiliza el mismo intento y verifica que el conteo se deriva de tres cobros activos. Introduce un cargo separado y verifica que la cantidad se mantiene.
3. Edita un cobro y verifica «3 de 4 pagos recibidos» y el aviso de que la edición conserva el conteo. Registra el cuarto, luego un quinto, y verifica los textos de cantidad alcanzada y pago adicional. Elimina un cobro y comprueba el conteo vigente al reabrir los formularios.
4. Con un proyecto histórico sin cantidad acordada, verifica el conteo actual y, al registrar, la proyección sin cantidad pendiente. En integración, agrega un cobro histórico activo en otra moneda y comprueba que aporta al conteo mientras el saldo en MXN se conserva. Revisa a 360 px. Simula que falla la lectura del resumen en edición y que una respuesta llega tras cerrar el editor. Verifica reintento, conservación de los campos y descarte de la respuesta tardía.
