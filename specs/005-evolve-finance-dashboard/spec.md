# Especificación de evolución financiera y tablero

**Estado** — Propuesta para revisión  
**Referencia visual** — [Tablero](dashboard-reference.png)

## Aclaraciones

### Sesión 2026-11-19

- Q: ¿Cómo se muestra el saldo cuando hay cobros históricos sin tipo de cambio? → A: Mostrar el saldo provisional con el aviso «Hay cobros sin convertir», excluir el proyecto del saldo global e indicar cuántos proyectos quedaron fuera.

- Q: ¿Qué proyectos aparecen al registrar un cobro desde el tablero? → A: Todos los proyectos propios, con los de saldo pendiente primero y el estado de cobro visible.

- Q: ¿En qué moneda se registran las deducciones de un cobro en USD? → A: La comisión y el impuesto descontados usan la moneda del abono. Los cargos pagados por separado se registran en MXN.

- Q: ¿Se puede corregir la moneda de un cobro ya guardado? → A: Sí, entre MXN y USD. Se revisan el importe y las deducciones en la moneda de destino y se captura el tipo de cambio si se cambia a USD. Los cargos separados se conservan.

## Escenarios y pruebas

### US1 · Revisar ingresos y saldos desde el nuevo tablero (P1)

Como profesional freelance quiero ver ingresos, pendientes y accesos frecuentes en una sola pantalla para saber qué atender y llegar rápido a cada cobro.

1. **Dado** noviembre de 2026 con cobros de MXN 4,500.00, 723.00 y 1,500.00, **cuando** selecciono noviembre, **entonces** la tarjeta principal muestra MXN 6,723.00 y tres cobros, y el panel mensual permite abrir esos mismos tres registros.
2. **Dado** un proyecto de MXN 22,000.00 con MXN 6,600.00 abonados, **cuando** filtro por pendientes, **entonces** la ficha del proyecto muestra acordado, abonado, saldo MXN 15,400.00 y avance monetario del 30 %. Cambiar el mes conserva los saldos vigentes y el filtro de proyectos.
3. **Dado** uno o más proyectos propios, **cuando** activo «Registrar cobro», **entonces** con uno voy al formulario y con varios elijo entre todos los proyectos propios. Los que tienen estado de cobro «Pendiente» aparecen primero y cada opción muestra su estado de cobro. Sin proyectos, la acción ofrece crear el primero. «Ver todos» abre la lista completa de cobros propios, con paginación si corresponde.
4. **Dado** un ancho de 360 px, **cuando** abro el tablero, **entonces** puedo usar el selector de mes, el filtro, los enlaces y el cierre de sesión sin desplazamiento horizontal. Una cuenta nueva puede crear un cliente o su primer proyecto desde el estado vacío.

### US2 · Registrar la entrega del trabajo (P1)

Como profesional freelance quiero actualizar el estado de entrega para distinguir el trabajo que falta del dinero que falta.

1. **Dado** un proyecto en curso que tiene saldo pendiente, **cuando** lo marco como entregado, **entonces** conserva el estado de cobro y el importe pendiente.
2. **Dado** un proyecto en curso con el monto liquidado, **cuando** abro su detalle, **entonces** veo «En curso» y «Liquidado». Puedo marcarlo entregado y volver a «En curso» para corregir un error.
3. **Dado** un proyecto histórico sin dato de entrega, **cuando** lo consulto, **entonces** veo «Sin registrar». La cuenta titular puede asignarle un estado.

### US3 · Registrar un abono en otra moneda (P2)

Como profesional freelance quiero guardar el abono original y el tipo de cambio aplicado para entender su equivalencia en las cuentas del proyecto.

1. **Dado** un proyecto acordado en MXN, **cuando** capturo USD 100.00 a MXN 17.25 por USD, **entonces** veo USD 100.00, la tasa y el equivalente MXN 1,725.00. El equivalente participa en el saldo y en el reporte por fecha de pago.
2. **Dado** un abono convertido, **cuando** corrijo solo su fecha, **entonces** se conserva el tipo de cambio y el importe se refleja en el periodo correspondiente. Si corrijo la tasa, la nueva equivalencia se calcula con esa tasa y el cambio queda en el historial.
3. **Dado** un cobro histórico en otra moneda sin tasa registrada, **cuando** consulto el proyecto, **entonces** cuenta como un pago activo y se identifica como pendiente de conversión. Sus importes quedan fuera de los agregados en MXN hasta capturar una conversión válida.
4. **Dado** un acuerdo de MXN 5,000.00 con MXN 2,000.00 abonados en MXN y un cobro USD sin tasa, **cuando** consulto el proyecto, **entonces** veo un saldo provisional de MXN 3,000.00 y el aviso «Hay cobros sin convertir». El tablero excluye el proyecto del saldo pendiente global e indica que un proyecto quedó fuera. Al completar la conversión de su último cobro sin tasa, el saldo vuelve a ser definitivo y participa en el total si es positivo.
5. **Dado** un abono de USD 100.00 con USD 5.00 de comisión descontada y un cargo posterior de MXN 75.00, **cuando** consulto su detalle, **entonces** veo la comisión original en USD con su equivalente y el cargo separado en MXN con su propia fecha.
6. **Dado** un cobro registrado en MXN por error, **cuando** lo corrijo a USD, **entonces** confirmo el importe y las deducciones expresados en USD y capturo el tipo de cambio. El cobro conserva su identificador y sus cargos separados. El historial registra los valores anteriores y los saldos y reportes reflejan la equivalencia corregida.

### US4 · Consultar y exportar el neto del mes (P2)

Como profesional freelance quiero distinguir cuánto me abonaron, cuánto pagué en comisiones e impuestos y cuánto recibí neto, con respaldo para cada total.

1. **Dado** un abono de MXN 1,500.00 con comisión de MXN 100.00 e impuesto descontado de MXN 50.00 en noviembre y un cargo separado de MXN 75.00 en diciembre, **cuando** consulto ambos meses, **entonces** el neto de noviembre es MXN 1,350.00 y el de diciembre es MXN −75.00 si ese es su único movimiento. Cada línea enlaza al cobro que la origina.
2. **Dado** un mes seleccionado, **cuando** descargo el CSV, **entonces** conserva el periodo, los conceptos, moneda original, tasa aplicada cuando corresponda y equivalentes. Los totales concuerdan con el desglose visible y la descarga contiene únicamente datos de la cuenta titular.

### US5 · Conservar la protección de acceso detrás del proxy (P1)

Como titular quiero que el límite de intentos de inicio de sesión conserve su comportamiento bajo el enrutamiento de desarrollo y despliegue.

1. **Dado** el origen HTTPS a través de proxies configurados, **cuando** se alcanzan los fallos previstos por la política desde un origen, **entonces** se aplica la pausa y un origen independiente conserva su propio contador.
2. **Dado** un salto sin confianza configurada, **cuando** llegan metadatos de IP de cliente, **entonces** el limitador utiliza la dirección de conexión disponible conforme a la política y conserva el mensaje genérico.

### Casos límite

- Mes sin cobros, año nuevo, saldo por determinar, exceso sobre el acuerdo, acuerdo cero o ausente, listas largas y pérdida de sesión durante una consulta.
- Conversión ausente o inválida, redondeos a medio centavo, cobro eliminado, reintentos de escritura y correcciones que cambian el mes.
- Un cargo de un cobro eliminado sale del reporte. Si el cargo y el abono caen en meses distintos, cada uno conserva su fecha para el desglose.

## Requisitos

### Requisitos funcionales

- **FR-01.** El tablero dispone la cabecera con periodo, «Registrar cobro» y «Cerrar sesión». La fila superior muestra abonado del mes seleccionado, saldo vigente y clientes con proyectos. Debajo se presentan saldos por proyecto y cobros del mes. La fila final muestra Clientes, Proyectos y Cobros guardados. Mantiene la jerarquía de la referencia y reorganiza las tarjetas en móvil.
- **FR-02.** El mes inicial corresponde al calendario de Ciudad de México. Cambiar el selector actualiza el abonado, número de cobros y desglose mensual. Saldo y colecciones conservan su alcance vigente. «Clientes activos» cuenta clientes con al menos un proyecto registrado. El indicador de proyectos cuenta todos los proyectos propios.
- **FR-03.** El saldo global suma únicamente saldos positivos determinados de proyectos propios. Un excedente no compensa otro proyecto. Si hay cobros activos sin convertir, el saldo que pueda calcularse con los datos disponibles se identifica como provisional y lleva el aviso «Hay cobros sin convertir». El saldo global y su conteo de pendientes excluyen esos proyectos y muestran cuántos quedaron fuera por esa causa. La tarjeta de proyecto identifica acordado, abonado y saldo. La barra representa abonado sobre acordado y se limita visualmente a 100 %. Si falta un acuerdo positivo, se muestra el estado y se omite la barra.
- **FR-04.** Las listas conservan enlaces al detalle. Cobros guardados muestra los cuatro más recientes, ordenados por fecha de pago descendente y con desempate estable, y permite consultar el historial completo. El selector incluye todos los proyectos propios, incluso los liquidados y aquellos con saldo por determinar. Agrupa primero los que tienen estado «Pendiente» y muestra el estado de cobro de cada proyecto. Si un abono supera el monto acordado, se conserva la regla vigente para el motivo del excedente. El selector y las listas excluyen datos ajenos. Los estados de carga, vacío, error y reintento son distinguibles.
- **FR-05.** Los estados de entrega son «Sin registrar», «En curso» y «Entregado». Los proyectos nuevos comienzan en curso. La entrega se modifica desde el detalle del proyecto y se muestra separada del cobro.
- **FR-06.** En la primera entrega de divisas se aceptan MXN y USD en abonos, con acuerdos y cargos separados en MXN. USD requiere una tasa positiva capturada manualmente en MXN por USD. La comisión y los impuestos descontados se capturan en la moneda del abono. Se conserva el importe original, sus deducciones, la tasa aplicada y los equivalentes. Sin conversión válida, el cobro histórico sigue visible con un aviso.
- **FR-07.** Los importes capturados admiten dos decimales y la tasa hasta seis. Se redondea al centavo más cercano, con mitades hacia arriba, al convertir el abono, las deducciones acumuladas y la comisión. El impuesto equivalente es la diferencia entre el total de deducciones convertido y la comisión convertida. El recibido equivale al abono convertido menos las deducciones convertidas. Si el impuesto absorbe una diferencia de redondeo, el detalle conserva el importe original. Una corrección reutiliza la tasa vigente del cobro salvo cambio explícito. No se aplica una tasa nueva por el paso del tiempo. Se permite corregir la moneda entre MXN y USD. Al cambiar la moneda, se requiere confirmar de forma explícita el importe y las deducciones en la moneda de destino y capturar una tasa nueva si el destino es USD. Si el destino es MXN, el cobro actualizado queda sin tasa de conversión y se conserva la tasa anterior en el historial cuando exista. La corrección mantiene el identificador y los cargos separados, actualiza los agregados afectados y registra en el historial la moneda, los importes y la tasa anteriores.
- **FR-08.** El reporte mensual separa abonado bruto, comisiones, impuestos y neto. Las retenciones se asignan a la fecha de pago y los cargos separados a su propia fecha. Se conservan ambos conceptos en el desglose. El neto puede ser negativo. La exportación CSV replica el periodo, los movimientos y la trazabilidad del reporte.
- **FR-09.** Las lecturas, modificaciones y descargas exigen sesión y pertenencia. Tras perder acceso se ocultan los datos y se descartan respuestas privadas tardías. Los datos privados quedan fuera de la caché de la PWA. Las escrituras conservan protección contra solicitudes de otro sitio, reintentos seguros y control de versiones donde corresponde.
- **FR-10.** La IP para el limitador se determina de acuerdo con una cadena de proxies autorizados. Cuando la procedencia no es confiable, se utiliza la conexión directa. Se conserva el límite y el mensaje de inicio de sesión existentes.

### Entidades

Proyecto, cliente, cobro, conversión aplicada, cargo separado, movimiento del reporte, resumen del tablero y origen del intento de inicio de sesión.

## Criterios de éxito

- **SC-01.** Los tres importes de la muestra suman MXN 6,723.00, los pendientes suman MXN 15,400.00 y ese saldo se conserva al cambiar de mes. El tablero se usa a 360 px y en escritorio.
- **SC-02.** Se verifican las dos combinaciones «Entregado con saldo» y «En curso liquidado» y el dato histórico sin registrar.
- **SC-03.** El abono USD 100.00 a 17.25 produce MXN 1,725.00 en detalle, saldo y reporte, con el mismo resultado tras reintentar la captura.
- **SC-04.** Cada celda monetaria del reporte se puede reconciliar con los movimientos exportados, incluso con cargos en otro mes. Los recursos y exportaciones de una cuenta quedan inaccesibles a otra.
- **SC-05.** La política de intentos se verifica con dos orígenes independientes, por conexión directa y a través de la topología HTTPS configurada.

## Supuestos y alcance

La imagen guía composición, distribución y estilo. Sus nombres, periodos e importes sirven como datos de ejemplo. La primera etapa de divisas usa USD, tasa manual y acuerdos en MXN. El CSV es la primera opción de exportación. El cálculo automático de obligaciones fiscales y un catálogo completo de monedas requieren especificaciones propias. La aceptación manual diferida en Android y el cambio de cuenta con un cobro abierto se retoman como validación transversal.
