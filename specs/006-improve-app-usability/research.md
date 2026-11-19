# Decisiones de diseño

| Decisión | Motivo | Alternativa evaluada |
| --- | --- | --- |
| Selector común de meses con borrador | El tablero ya dispone de un diálogo que se puede reutilizar. Aplicar permite revisar el año y mes antes de disparar una consulta. | Lista cronológica larga y control nativo con presentación dependiente del navegador. |
| Temas mediante tokens semánticos y preferencia local | Landing y vistas privadas comparten Inter y pueden aplicar el tema antes de renderizar. El ajuste no requiere datos de cuenta. | Persistencia en el servidor, que implica sincronización y cambios de esquema fuera de esta fase. |
| Historial filtrado y resumido en PostgreSQL | El listado actual solo pagina y el resumen debe representar el filtro completo. Agrupar por moneda conserva significado financiero. | Sumar la página actual o descargar todos los cobros en el cliente. |
| Editar proyecto mediante PATCH aditivo | El endpoint vigente ya distingue propietario, cliente y entrega. Se deben mantener esas reglas mientras se permite ajustar el acuerdo. | Crear otro endpoint con validaciones de pertenencia duplicadas. |
| Mantener tipo de cargo existente | La captura 6 muestra un concepto de texto. Los reportes actuales reconocen comisiones e impuestos. Se conserva el selector de tipo y se adapta la composición. | Introducir categorías libres y ampliar el significado del neto. |
| Monto por pago orientativo | El proyecto guarda monto y número de pagos. Dividir y redondear la vista previa informa sin asumir un calendario de cuotas. | Crear cuotas iguales automáticamente, incluso cuando el total no se divide en centavos exactos. |
| Historial separado para ajustes con excedente | Una razón pertenece al cambio concreto de monto. Conservar cada ajuste permite verificar la decisión después de futuras correcciones. Usar el bloqueo del proyecto y la transacción existentes evita guardar una razón para una proyección anterior. | Un campo de motivo en el proyecto se sobrescribiría con el siguiente ajuste. |
| Proyecto fijo en la corrección de cobro | La edición actual preserva la asociación y versiona cambios del cobro. Mostrar el proyecto como dato de solo lectura mantiene los saldos de los demás proyectos y permite rechazar la reasignación también en el servidor. | Mover un cobro requeriría coordinar dos proyectos y cambiar el contrato de corrección. |
