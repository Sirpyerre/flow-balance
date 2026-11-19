# Contratos de interfaz y datos

## Navegación, acciones y tema

La navegación resalta la sección activa. En móvil muestra cuatro destinos etiquetados y contempla el área segura de la PWA. En escritorio cada destino tiene nombre accesible. Las acciones primarias usan el énfasis del tema, las de cancelación usan borde y las de eliminación usan estilo de peligro. Los botones de guardar muestran un estado mientras la operación está en curso. El foco vuelve al disparador al cerrar un diálogo. La preferencia de tema y sus estados se aplican a landing, autenticación y vistas privadas.

## Listados de clientes y proyectos

Los destinos «Clientes» y «Proyectos» muestran registros de la cuenta, acceso a detalle y acciones «Nuevo cliente» y «Nuevo proyecto». Se pueden alimentar de las lecturas privadas existentes. Los proyectos identifican a su cliente y el orden es estable por nombre e identificador. En móvil se muestran filas tocables dentro de una tarjeta y se conserva el acceso de alta.

## Historial y periodos

- `GET /api/payments?month=YYYY-MM&limit=…&cursor=…` acepta el mes opcional. Sin mes equivale a «Todos». El rango empieza el primer día inclusive y termina al inicio del mes siguiente. Cambiar filtro reinicia la paginación e invalida respuestas anteriores. Reutilizar el orden fecha e identificador y validar que el cursor y el filtro sean compatibles.
- Mantener `payments` y `nextCursor`. Agregar `summary` con `paymentCount`, `latestPaidOn` nulo cuando no hay filas y `totalsByCurrency` con importes originales en cadenas decimales. Los meses rápidos provienen de los tres meses más recientes con cobros propios activos, independientemente del filtro. El resumen cubre todo el filtro antes del cursor.
- En el tablero, el gráfico selecciona un periodo de forma directa y el diálogo requiere «Aplicar». En el historial, los botones de filtro rápido seleccionan directamente y «Elegir mes» abre el mismo diálogo.

## Proyectos y cobros

- Ampliar el PATCH de proyectos para origen, monto y cantidad de pagos, conservando clientes propios y la restricción vigente cuando existen cobros, incluidos los eliminados lógicamente, conforme a la regla vigente. Cambios inválidos se devuelven como errores de campo. Calcular el nuevo estado de cobro desde el acuerdo actualizado, conservando los estados de entrega y los cobros.
- `POST /api/projects/{id}/agreement-preview` recibe el monto acordado propuesto como cadena decimal MXN. Con sesión y CSRF, devuelve el abonado computable, saldo y excedente proyectados, el indicador provisional y `requiresAdjustmentReason`. Responde 404 para un proyecto ajeno. La consulta no modifica el acuerdo.
- `PATCH /api/projects/{id}` admite `agreementAdjustmentReason` con 1 a 250 caracteres después de recortar espacios cuando el nuevo monto reduce el anterior y queda por debajo del abonado computable. La condición se recalcula bajo el bloqueo del proyecto antes de escribir. Sin razón válida devuelve 422 con error en ese campo y el excedente actual para presentar el motivo y volver a intentar. Guardar acuerdo y razón es atómico. Los cambios de otros campos conservan sus validaciones aunque ya exista excedente. Repetir de inmediato el mismo monto guardado no genera otra revisión.
- En alta y edición, reutilizar la validación de importes y la proyección MXN. Para edición, la vista previa debe identificar el cobro y su versión, verificar pertenencia y considerar el reemplazo. Mantener `If-Match`, la clave de idempotencia y la auditoría al guardar. La vista previa de edición deriva el proyecto del cobro existente. El PATCH rechaza un `projectId` con 422 y conserva la asignación y los cargos. Si un proyecto queda fuera del agregado por conversión pendiente, mostrar el saldo como provisional.
- Para registrar cargos, conservar tipo Comisión o Impuesto, fecha, importe MXN, reintentos y asociación al cobro. Mantener los endpoints actuales y proteger consultas y escrituras con la sesión. Las respuestas privadas siguen sin caché.
