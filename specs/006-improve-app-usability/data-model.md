# Modelo de datos

| Entidad o proyección | Campos y regla |
| --- | --- |
| Preferencia de tema | `dark` o `light`, local al perfil. Ausencia o valor inválido usa oscuro. Contiene únicamente la preferencia. |
| Selector de periodo | Periodo aplicado y borrador `YYYY-MM`. Cancelar descarta el borrador. «Ir a este mes» prepara el periodo actual según la fecha de la aplicación. |
| Historial de cobros | Cobros activos propios, ordenados por fecha e identificador descendentes. Resumen con cantidad, sumas exactas agrupadas por moneda y mayor fecha de pago del conjunto filtrado. |
| Acuerdo del proyecto | Cliente, nombre, origen, monto MXN, cantidad de pagos y estado de entrega vigente. Una edición parcial conserva campos omitidos. Las filas históricas con campos ausentes pueden conservarlos hasta completarlos según las reglas de alta. |
| Ajuste de acuerdo con excedente | Registro asociado al proyecto y su cuenta. Contiene identificador, fecha, monto anterior y nuevo en MXN, total abonado computable, excedente calculado y razón. La pertenencia se valida con clave foránea compuesta del proyecto. Se registra en la misma transacción que la reducción. Los montos usan decimales exactos. El abonado y el excedente admiten la misma magnitud que la proyección acumulada en MXN. |
| Vista previa de acuerdo | Promedio en MXN a dos decimales, mitades hacia arriba, derivado de monto y número de pagos válidos. No se persiste. |
| Resumen de cobro | Datos de proyecto, conteo de pagos y saldos vigentes y proyectados. Conserva moneda original, tipo de cambio y aviso de conversión. Editar sustituye el cobro en el cálculo y conserva su proyecto y sus cargos. |
| Borrador de formulario | Estado temporal al abrir alta de cliente desde proyecto. Ligado a la sesión en pantalla. Limpiar al cerrar sesión, cancelar de forma explícita o terminar el flujo. |
