# Especificación de funcionalidad · Usabilidad de Flow Balance

**Estado** · Borrador para revisión · **Funcionalidad 006**

## Aclaraciones

### Sesión 2026-11-19

- Q: ¿Debe poder cambiarse el proyecto de un cobro ya guardado? → A: El proyecto queda fijo. Si se eligió mal, se elimina el cobro y se registra en el proyecto correcto.
- Q: ¿Qué debe ocurrir al reducir el monto acordado por debajo de lo abonado? → A: Permitir guardar con una razón obligatoria y mostrar el excedente previsto. Los errores del alta se pueden corregir desde «Editar proyecto».
- Q: ¿Qué comportamiento debe tener el campo «Concepto» de los cargos separados? → A: Mantener el selector de tipo «Comisión» o «Impuesto» y adoptar la distribución de la maqueta.

## Escenarios de usuario y pruebas

### US1 · Navegar y confirmar acciones de forma coherente (P1)

1. Dado un navegador de escritorio, al abrir una pantalla privada veo navegación lateral y la sección activa. A 360 px veo navegación inferior con Tablero, Cobros, Proyectos y Clientes, texto visible y contenido por encima de la barra.
2. Dado un formulario, su acción principal dice «Guardar cliente», «Guardar proyecto», «Guardar cobro», «Guardar cambios» o «Aplicar», según corresponda. «Cancelar» deja los datos guardados intactos y vuelve al contexto anterior. Si hay cambios sin guardar, puedo continuar editando o descartarlos explícitamente.
3. Dado un cobro existente, «Eliminar cobro» se distingue de «Editar cobro» y abre una confirmación con la fecha y el importe. Cancelar conserva el registro. Confirmar respeta el flujo vigente de eliminación y sus efectos en reportes.

### US2 · Elegir tema claro u oscuro (P1)

1. Dado el primer acceso, veo el modo oscuro actual. Puedo elegir «Claro» u «Oscuro» desde un control identificado en la landing y en la navegación privada. La selección se conserva al recargar, navegar o reabrir la PWA en el mismo perfil del navegador.
2. Dado el modo claro, las tarjetas claras, el fondo y el acento violeta siguen las referencias. En modo oscuro se conserva la identidad actual. Los dos modos conservan Inter y presentan estados, foco y alertas legibles.

### US3 · Consultar el historial de cobros y elegir periodos (P1)

1. Dado un historial paginado, «Cobros guardados» muestra cantidad, total del periodo filtrado, fecha del último cobro, fecha y mes de cada fila, importe original y enlace «Ver». Cargar otra página mantiene filtro y resumen. En móvil cada fila conserva estos datos en dos columnas, con fecha y mes a la izquierda e importe y acción a la derecha.
2. Dado un historial de varios años, puedo usar «Todos», los tres meses más recientes con cobros y «Elegir mes». Los meses rápidos incluyen el año. Si elijo un periodo vacío veo cero cobros y una opción para cambiar el filtro. Los totales en monedas distintas se presentan por moneda.
3. Dado el selector abierto desde el tablero o historial, veo los doce meses del año, flechas, campo de año, «Ir a este mes», «Cancelar» y «Aplicar». Elegir mes o usar el atajo prepara la selección. Aplicar actualiza los datos. Cancelar o pulsar Escape conserva el periodo anterior. Los meses futuros están deshabilitados y puedo escribir un año histórico directamente.

### US4 · Crear clientes y preparar o editar acuerdos (P2)

1. Dada la pantalla «Nuevo cliente», puedo capturar el nombre y consultar los clientes registrados en un panel de apoyo. Si llegué desde un proyecto, guardar me devuelve al formulario con el cliente nuevo seleccionado y conserva los campos ya capturados.
2. Dado un proyecto nuevo, puedo elegir cliente, nombre, origen, monto acordado en MXN y cantidad de pagos. Con monto válido y dos o más pagos, la vista previa muestra el monto orientativo por pago redondeado al centavo, sin crear cuotas ni cobros. Para un solo pago muestra el total acordado. Los campos incompletos conservan la vista previa sin importe.
3. Dado un proyecto existente, «Editar proyecto» muestra el acuerdo y un panel con estado de cobro, avance monetario, pagos registrados, abonado y saldo. Puedo guardar nombre, origen, monto y cantidad de pagos. Puedo cambiar el cliente cuando el proyecto cumple la restricción vigente para reasignación. Si cambio el acuerdo, se recalcula el saldo con los cobros existentes. Si reduzco el monto acordado por debajo de lo ya abonado, veo el excedente antes de guardar y debo indicar una razón. La razón queda registrada con el ajuste. Si corrijo un dato del alta y el cambio de monto no deja excedente, puedo guardar con las validaciones habituales. La reasignación de cliente sigue sujeta a la restricción vigente. El estado de entrega se conserva.

### US5 · Registrar, editar y entender un cobro (P2)

1. Dada la entrada desde Cobros, puedo elegir un proyecto propio y ver su resumen. Al entrar desde un proyecto, ese proyecto está preseleccionado. La pantalla presenta fecha, fuente, moneda, importe, comisión e impuestos. En USD muestra el tipo de cambio y sus equivalentes conforme a la regla vigente.
2. Dado un abono de MXN 1,000.00 sobre un proyecto con 2 de 4 pagos y saldo de MXN 15,400.00, la vista previa muestra el neto del cobro y anticipa 3 de 4 pagos y saldo de MXN 14,400.00. Si el saldo es provisional, el aviso sigue visible. Editar un cobro existente mantiene el conteo y proyecta el reemplazo del importe anterior.
3. Dado el detalle de un cobro, veo sus datos y tarjetas separadas para abonado, comisión, impuestos y dinero recibido. Puedo editar, eliminar, consultar cargos separados y registrar un cargo desde el mismo detalle. Para registrar un cargo separado elijo «Comisión» o «Impuesto» e indico fecha e importe en MXN. Al guardarlo aparece en la lista de cargos con su tipo, fecha e importe y se refleja en el reporte del mes de su propia fecha.
4. Dado un cobro guardado, al editar veo su proyecto como un dato de solo lectura. Si intento cambiarlo mediante una solicitud de edición, el sistema rechaza el cambio y conserva el cobro, sus cargos y los saldos de ambos proyectos. Para corregir un proyecto equivocado, uso el flujo de eliminación y registro un cobro nuevo en el proyecto correcto.

### US6 · Usar el tablero y los flujos en móvil (P2)

1. A 360 px, el tablero muestra primero el abonado con «Registrar cobro» en la misma tarjeta, seguido de dos tarjetas compactas, gráfica, saldos, últimos cobros y accesos a clientes y proyectos. Los accesos llevan a listados propios con nombre, enlace de detalle, alta y estado vacío. El listado de proyectos identifica también al cliente.
2. A 360 px, los formularios y detalles distribuyen su contenido en una columna. Los paneles de apoyo permanecen disponibles. En la captura de cobros el resumen de avance y saldo queda visible antes de guardar. Las acciones y la navegación inferior respetan el área segura.
3. En escritorio, las pantallas con panel de apoyo usan dos columnas. El reporte del tablero, sus enlaces y el CSV siguen accesibles en escritorio y móvil.

### Casos límite

- Cuenta sin proyectos, sin cobros o con datos en varios años. Mes sin resultados o solo con cargos separados. Cobro en USD sin tasa histórica.
- Cambio de filtro con una consulta anterior en curso, fallo de red, doble toque, corrección desactualizada y sesión vencida. Mantener datos protegidos y mensajes recuperables.
- Dado un proyecto con MXN 6,600.00 abonados, reducir el acuerdo a MXN 6,000.00 muestra un excedente de MXN 600.00 y exige una razón antes de guardar. Corregir el nombre o el origen se permite con las validaciones habituales.
- Nombre largo, importes elevados, botones con texto largo, zoom al 200 %, teclado y navegación por lector de pantalla.
- Al cambiar de proyecto con un borrador de cobro, conservar los valores que siguen siendo válidos y recalcular el resumen. El guardado exige el resumen y validación del proyecto seleccionado.

## Requisitos

| ID | Requisito | Verificación |
| --- | --- | --- |
| FR-01 | Compartir navegación y jerarquía de acciones en pantallas privadas. | US1, US6 |
| FR-02 | Ofrecer tema claro y oscuro con preferencia local y aplicación desde el primer renderizado. | US2 |
| FR-03 | Mostrar resumen, filtros y paginación del historial para la cuenta activa. | US3 |
| FR-04 | Usar selector común por mes y año con selección provisional y aplicación explícita. | US3 |
| FR-05 | Organizar altas de clientes y proyectos con panel de apoyo y retorno conservando el borrador. | US4 |
| FR-06 | Habilitar la edición del acuerdo con validación de pertenencia, cálculo de saldos existentes y razón registrada si la reducción del monto deja un excedente. | US4 |
| FR-07 | Mantener captura, corrección, baja y cargos con un layout consistente, ayuda financiera visible y proyecto fijo durante la edición. | US1, US5 |
| FR-08 | Adaptar toda la navegación privada y sus pantallas a móvil y escritorio. | US6 |

### Entidades clave

Preferencia de tema, periodo seleccionado, resumen de historial, borrador de formulario, acuerdo de proyecto y resumen de cobro.

## Criterios de éxito

- **CE-01.** A 360 y 1080 px, los nueve diseños de referencia se pueden recorrer en ambos temas. A 360 px el documento cabe en el ancho de pantalla y cualquier desplazamiento lateral de filtros queda dentro de su banda.
- **CE-02.** En el selector aparecen doce meses y se puede elegir un periodo de otro año desde el campo de año, el mes y «Aplicar», sin recorrer una lista larga. Cancelar y Escape dejan el periodo y los totales intactos.
- **CE-03.** El historial de siete cobros MXN de la referencia suma MXN 24,323.00 y marca el 13 de noviembre de 2026 como último cobro. Noviembre muestra tres cobros por MXN 6,723.00 aun con páginas de dos filas.
- **CE-04.** Se puede crear un cliente, un proyecto y un cobro desde un ancho de 360 px. Un doble toque al guardar el cobro crea una sola fila. Cancelar una edición conserva su valor anterior.
- **CE-05.** Una segunda cuenta no obtiene registros, resúmenes ni totales ajenos. Las vistas privadas se limpian al perder la sesión.

## Supuestos y alcance

- La segunda captura corresponde a edición de **proyecto**. Se cubre además el rediseño del flujo existente de edición de **cobro**.
- «Total listado» representa todos los cobros del filtro, aunque solo se haya cargado la primera página. Se muestran totales separados por moneda. El reporte mensual conserva su significado y sus equivalentes en MXN.
- La preferencia se guarda por perfil de navegador o PWA. El tema se comparte con la landing. La autenticación, la moneda de acuerdos, los tipos de cargo y las reglas de saldo y redondeo siguen sus contratos vigentes.
- Las capturas son referencias de composición. El formulario de cargos separados usa las categorías «Comisión» e «Impuesto». Los conceptos libres, las categorías adicionales y un calendario de cuotas quedan fuera de esta entrega.
