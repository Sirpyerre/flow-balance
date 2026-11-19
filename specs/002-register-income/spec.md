# Especificación de funcionalidad: Registro de ingresos

**Directorio**: `specs/002-register-income`  
**Fecha**: 2026-11-18  
**Estado**: Borrador  
**Entrada**: Continuar con la fase de registro de ingresos de Flow Balance.

## Aclaraciones

### Sesión 2026-11-18

- Pregunta sobre la cantidad de pagos → Se captura cuántos pagos se acordaron y se muestra el avance según los cobros registrados, sin un calendario de parcialidades.
- Pregunta sobre la fuente → Se registra cómo se consiguió el proyecto y, por separado, cómo llegó cada cobro.
- Pregunta sobre cobros por encima del monto acordado → Se permite guardar con un aviso del excedente y una razón obligatoria que queda en el detalle.
- Pregunta sobre comisiones e impuestos → Se registran tanto los descuentos al recibir el pago como los cargos pagados por separado, con fecha propia y vínculo al cobro.
- Pregunta sobre monedas → El MVP usa MXN para el monto acordado, los cobros y los cargos pagados por separado.

## Escenarios de usuario y pruebas

### Problema

El tablero ya puede mostrar cobros de una cuenta. La persona freelance necesita dar de alta clientes y proyectos, identificar cómo consiguió cada proyecto y de dónde viene cada ingreso y capturar los pagos recibidos desde el escritorio o el celular, saber cuánto abonó su cliente y cuánto recibió después de las deducciones, y comprobar en qué mes quedó registrado.

### Historia 1 — Dar de alta un cliente y su proyecto (P1)

Como profesional freelance, quiero registrar un cliente y crear un proyecto asociado para poder organizar y capturar sus pagos.

**Valor.** Permite empezar con una cuenta vacía y reutilizar un cliente en varios proyectos.

**Prueba independiente.** Desde un tablero vacío, dar de alta un cliente, crear dos proyectos para ese cliente y abrir el detalle de uno.

1. **Dado** un tablero vacío, **cuando** se registre el cliente «Estudio Norte» y se guarde el proyecto «Identidad visual» con monto acordado de MXN 5,000.00, tres pagos acordados y origen «Recomendación», **entonces** el detalle mostrará el cliente, el proyecto, el origen, el monto acordado y «0 de 3 pagos recibidos».
2. **Dado** un cliente propio existente, **cuando** se cree otro proyecto, **entonces** se podrá seleccionar ese cliente sin volver a capturar su nombre. Un cliente ajeno o inexistente se rechaza sin crear un proyecto.

### Historia 2 — Registrar un cobro desde un proyecto (P1)

Como profesional freelance, quiero registrar el abono que recibí en un proyecto para dejar constancia del ingreso y de su fecha de cobro.

**Valor.** Habilita la captura de ingresos con un recorrido que cabe en una sola pantalla de formulario.

**Prueba independiente.** Con un proyecto propio y una sesión iniciada, abrir el formulario del proyecto, guardar un abono y verificar su detalle.

1. **Dado** un proyecto propio, **cuando** la persona seleccione «Registrar cobro» y guarde MXN 3,000.00 con fecha 30 de noviembre de 2026 y fuente «Cliente directo», **entonces** verá una confirmación y el detalle mostrará el proyecto, la fecha, la fuente y los MXN 3,000.00 abonados y recibidos.
2. **Dado** un abono de MXN 3,000.00 con MXN 150.25 de comisión y MXN 49.75 de impuestos descontados al cobrar, **cuando** se guarde, **entonces** el detalle mostrará los cuatro importes por separado y MXN 2,800.00 como dinero recibido.
3. **Dado** un formulario con datos válidos salvo un importe de MXN 10.001, **cuando** se intente guardar, **entonces** se indicará el campo de importe, se conservarán los demás datos y no se creará el cobro. Al corregir a MXN 10.01 se podrá guardar.
4. **Dado** un proyecto en cualquier estado de entrega, **cuando** se registre un abono, **entonces** el cobro quedará asociado y el estado de entrega conservará su valor.

5. **Dado** un proyecto con un primer cobro de MXN 3,000.00 de cliente directo, **cuando** se registre otro de MXN 800.00 de plataforma «Upwork», **entonces** el origen «Recomendación» permanecerá en el proyecto y el detalle mostrará «2 de 3 pagos recibidos» para un proyecto con tres pagos acordados, MXN 3,800.00 abonados y la fuente individual de cada pago.

6. **Dado** un proyecto acordado por MXN 5,000.00 con MXN 4,800.00 abonados, **cuando** se capture otro cobro de MXN 500.00, **entonces** se advertirá que el total quedará MXN 300.00 por encima del monto acordado. El cobro se podrá guardar al indicar «Trabajo adicional». El detalle mostrará la razón y el monto acordado seguirá siendo MXN 5,000.00.

7. **Dado** un cobro del 30 de noviembre de 2026 con MXN 2,800.00 recibidos, **cuando** se registren una comisión de MXN 50.00 pagada el 2 de diciembre y un impuesto de MXN 25.00 pagado el 3 de diciembre, **entonces** el detalle mostrará ambos cargos con sus respectivas fechas. El dinero recibido al cobrar seguirá siendo MXN 2,800.00 y el reporte de importe abonado conservará el mes y valor del abono original.

### Historia 3 — Reintentar un guardado sin duplicar el abono (P1)

Como profesional freelance, quiero poder reintentar si pierdo la conexión, sin duplicar mis ingresos.

**Valor.** Mantiene exactos los importes del proyecto y del reporte cuando el teléfono pierde la respuesta.

**Prueba independiente.** Guardar desde un proyecto propio y repetir la misma operación antes de recibir confirmación.

1. **Dado** un formulario válido, **cuando** se toque dos veces «Guardar cobro» o se reintente tras perder la respuesta a un guardado exitoso, **entonces** existirá un solo cobro y se mostrará la confirmación del original, aun si un cobro posterior hizo que el proyecto superara el monto acordado.
2. **Dado** un cobro ya confirmado, **cuando** la persona inicie otra captura de forma explícita con el mismo proyecto, fecha e importe, **entonces** podrá guardar un segundo cobro legítimo.

### Historia 4 — Consultar el cobro en el periodo correcto (P1)

Como profesional freelance, quiero localizar mis cobros en el reporte mensual para comprobar qué abonos forman el total.

**Valor.** Permite conciliar el total con los registros y mantiene privados los ingresos.

**Prueba independiente.** Con cobros propios a ambos lados del cierre de mes y un cobro de otra cuenta, consultar el reporte y abrir su detalle.

1. **Dado** que Ana registró MXN 3,000.00 el 30 de noviembre y MXN 800.00 el 1 de diciembre de 2026, **cuando** seleccione noviembre, **entonces** el total de importe abonado será MXN 3,000.00 y podrá abrir el cobro que lo compone. En diciembre verá MXN 800.00 y su cobro correspondiente.
2. **Dado** que Ana registró MXN 3,000.00 abonados y recibió MXN 2,800.00 en noviembre, **cuando** consulte el total existente de importe abonado, **entonces** ese cobro aportará MXN 3,000.00 al total y su detalle mostrará los MXN 2,800.00 recibidos.
3. **Dado** que Luis tiene su propia cuenta, **cuando** consulte noviembre, intente abrir el cobro de Ana o trate de guardar en el proyecto de Ana usando su identificador, **entonces** verá únicamente sus propios totales, se denegarán ambos accesos sin revelar datos de Ana y no se creará ningún cobro ajeno.

### Casos límite

- Sin proyectos propios, la entrada a captura guía a crear un proyecto antes de registrar un cobro.
- Se rechazan fecha ausente, inválida o posterior al día actual de la cuenta, abono vacío, cero o negativo, deducciones negativas, más de dos decimales y deducciones cuya suma supere el abono. Si iguala el abono, el dinero recibido es MXN 0.00.
- Si el envío falla antes de guardarse, se muestran el error y la opción de reintentar. Si se pierde la respuesta, el formulario conserva sus datos mientras siga abierto y el reintento recupera la confirmación del cobro si ya existe.
- Si cambian los datos de un envío que ya produjo un cobro y se intenta reutilizar ese mismo guardado, se avisa que ya existe un cobro y se conserva su contenido.
- Con sesión vencida se pide iniciar sesión. La captura requiere conexión y los datos privados del cobro no quedan disponibles sin conexión.

## Requisitos

### Requisitos funcionales

- **FR-001.** Desde el detalle de un proyecto propio, una persona con sesión iniciada puede abrir una captura de una pantalla, completar los campos y guardar. El mismo recorrido está disponible en navegador de escritorio y PWA móvil. Sin proyectos propios se ofrece la opción de crear uno.
- **FR-002.** Cada cobro requiere proyecto, fecha efectiva de pago e importe abonado positivo. La fecha se propone con el día actual de la cuenta y puede corregirse a una fecha anterior válida. Las fechas futuras se rechazan. Cada importe se expresa en MXN.
- **FR-003.** La comisión de plataforma y los impuestos descontados a este cobro son campos opcionales e independientes, ambos con valor cero si se omiten. El dinero efectivamente recibido equivale a importe abonado menos comisión de plataforma menos impuestos descontados. Se muestra antes de guardar y queda disponible en el detalle. La suma de deducciones no puede superar el abono.
- **FR-004.** Los importes capturados admiten hasta 14 dígitos enteros y dos decimales. Se rechazan las entradas de mayor precisión, sin redondearlas. El cálculo y la suma mensual se hacen con decimales exactos y se muestran con dos decimales, incluyendo ceros correspondientes.
- **FR-005.** Si falla una validación, se identifica el campo con un mensaje en español de México, se conservan los datos válidos y no se guarda ningún cobro. El guardado exitoso muestra el detalle con cliente, proyecto, fecha y los cuatro importes de captura y cálculo.
- **FR-006.** Cada operación de guardado crea a lo sumo un cobro. Un doble toque o reintento del mismo contenido produce la misma confirmación. Reutilizar una operación exitosa con contenido distinto se rechaza sin cambios. Una nueva captura explícita puede producir otro cobro aun cuando coincidan los datos.
- **FR-007.** Cada cuenta registra y consulta únicamente cobros de sus propios proyectos. Los identificadores ajenos se tratan igual que los inexistentes. El registro mantiene el monto acordado y el estado de entrega del proyecto. Con al menos un cobro guardado, se conserva la asociación con el cliente.
- **FR-008.** El reporte mensual existente suma el importe abonado de los cobros propios en MXN, por fecha efectiva de pago. Cada total se puede rastrear hasta los cobros que lo componen y sus detalles. Un reporte sin cobros muestra MXN 0.00 y una lista vacía.
- **FR-009.** El guardado requiere conexión y sesión vigente. Desde el celular sin conexión, los datos privados de cobros y reportes quedan inaccesibles.

- **FR-010.** El alta de cliente requiere nombre. El alta de proyecto requiere nombre, un cliente propio, el monto acordado positivo en MXN y la cantidad de pagos acordados como entero positivo. Ambos nombres admiten de 1 a 100 caracteres tras quitar espacios exteriores. El proyecto muestra monto acordado, cantidad de cobros registrados frente a los pagos acordados e importe total abonado. Los proyectos previos sin monto o cantidad acordada muestran «Sin registrar» en el dato correspondiente y conservan el conteo de cobros.
- **FR-011.** Cada pago nuevo requiere fuente de ingreso. Las opciones son «Cliente directo», «Plataforma» y «Otra». Plataforma y Otra requieren un nombre de 1 a 100 caracteres tras quitar espacios exteriores. Cliente directo identifica el origen mediante el cliente del proyecto. Cada cobro conserva su propia fuente.
- **FR-012.** Registrar un pago adicional no modifica pagos previos ni el monto acordado. Cada cobro guardado cuenta una vez en el avance. Si se supera la cantidad acordada, se muestra la cantidad real. El conteo es informativo. El importe total abonado es la suma exacta de los cobros registrados. Los cobros previos sin fuente muestran «Sin registrar».

- **FR-013.** Cada proyecto nuevo requiere un origen que describa cómo se consiguió el trabajo, con texto de 1 a 100 caracteres tras quitar espacios exteriores. Se muestra en el detalle del proyecto y se conserva al registrar cobros. Las fuentes se capturan por cobro. Los proyectos previos sin origen muestran «Sin registrar».

- **FR-014.** Si el total abonado del proyecto con el nuevo pago supera el monto acordado, se muestra la diferencia exacta y se solicita una razón de 1 a 250 caracteres tras quitar espacios exteriores. La razón queda asociada al cobro y se muestra en su detalle. Llegar exactamente al monto acordado no requiere razón. Si el proyecto histórico carece de monto acordado, se omite la comparación. El guardado verifica el total vigente. Si otro cobro cambió el total y ahora se requiere una razón, se solicita antes de guardar y se conservan los datos capturados. Si ya hay una razón válida, se guarda sin pedir una nueva confirmación y se muestra el excedente vigente al confirmar el cobro.

- **FR-015.** Desde el detalle de un cobro propio se pueden registrar uno o más cargos pagados por separado. Cada cargo tiene tipo «Comisión» o «Impuesto», fecha efectiva de pago válida y hasta el día actual de la cuenta, e importe positivo en MXN con la precisión de FR-004. El detalle lista el tipo, fecha e importe de cada cargo. Estos cargos no cambian el dinero recibido al cobrar, el importe abonado, el conteo de pagos ni el periodo del reporte de abonos.
- **FR-016.** Solo la cuenta titular puede registrar y consultar cargos vinculados a sus cobros. Un cobro ajeno recibe la misma respuesta que uno inexistente. Un doble toque o reintento de la misma operación de registro conserva un solo cargo, sin impedir otra captura explícita de los mismos datos.

- **FR-017.** El monto acordado, los cobros y los cargos pagados por separado se registran exclusivamente en MXN y muestran la moneda junto a cada importe. Las solicitudes con otra moneda se rechazan antes de guardar.

### Entidades principales

- **Cobro.** Abono efectivamente realizado por un cliente para un proyecto. Conserva titular, proyecto, fecha de pago, moneda, fuente, importe abonado, comisión de plataforma, impuestos descontados y razón del excedente cuando corresponda. El dinero recibido tiene su propia regla de cálculo.
- **Cargo pagado por separado.** Comisión o impuesto efectivamente pagado en una operación distinta a la recepción del abono. Pertenece a un cobro de la misma cuenta y conserva tipo, fecha, importe y moneda.
- **Proyecto y cliente.** El cobro pertenece a un proyecto de la misma cuenta. El cliente se obtiene del proyecto. Un proyecto conserva el origen del trabajo y la cantidad acordada de pagos y puede tener varios abonos.
- **Reporte mensual.** Vista de los importes abonados de una cuenta, agrupados por mes y moneda, con acceso a los cobros que componen el total.

## Criterios de éxito

- **SC-001.** A 360 px de ancho, desde un proyecto propio, se registra un cobro en un formulario, con una apertura y un guardado, sin desplazamiento horizontal.
- **SC-002.** Los casos de MXN 3,000.00 sin deducciones, MXN 3,000.00 con las dos deducciones del ejemplo, y MXN 0.30 con deducciones de MXN 0.10 y MXN 0.20 producen respectivamente MXN 3,000.00, MXN 2,800.00 y MXN 0.00 recibidos. Todos los casos inválidos de precisión o deducciones se rechazan.
- **SC-003.** Una captura con doble toque y otra con respuesta perdida seguida de un reintento producen exactamente un cobro cada una. Dos capturas explícitas de los mismos datos producen exactamente dos.
- **SC-004.** En la prueba de dos cuentas y dos meses, los totales coinciden exactamente con los cobros propios del periodo. Todos los intentos de abrir un cobro ajeno o guardar en un proyecto ajeno se deniegan.
- **SC-005.** Un cobro guardado en móvil queda disponible desde escritorio al volver a entrar a la misma cuenta. Al perder conectividad se impide guardar y consultar datos privados.

- **SC-006.** Se puede pasar de una cuenta vacía a un cliente y un proyecto propios. Tras capturar los dos pagos del ejemplo se ve «2 de 3 pagos recibidos», MXN 3,800.00 abonados, el monto acordado de MXN 5,000.00 por separado, el origen del proyecto y ambas fuentes.

- **SC-007.** Un total de MXN 5,300.00 sobre MXN 5,000.00 acordados muestra un excedente de MXN 300.00. El guardado requiere una razón visible en el detalle del cobro y conserva el monto acordado. Si un cobro concurrente eleva el excedente de MXN 300.00 a MXN 600.00 y ya hay una razón válida, se guarda y la confirmación muestra MXN 600.00.

- **SC-008.** Dos cargos pagados en diciembre y asociados a un cobro de noviembre aparecen con sus fechas en el detalle. El dinero recibido al cobrar y el total mensual abonado conservan sus valores y un reintento del mismo cargo no lo duplica.

- **SC-009.** Un proyecto, un cobro y un cargo válidos se guardan en MXN. Si alguno se intenta guardar en USD, se rechaza la operación y no se crea el registro.

## Supuestos, dependencias y alcance

- Este primer incremento reutiliza cuentas, proyectos, cobros y reporte mensual del módulo de acceso. Los cobros existentes en MXN conservan su importe abonado y se presentan con deducciones de MXN 0.00.
- El formulario de cobro captura manualmente las comisiones e impuestos descontados en ese momento. Los cargos efectivamente pagados por separado se capturan desde el detalle del cobro. El reporte existente conserva el alcance de importe abonado. El incremento abarca proyectos, cobros y cargos en MXN y deja para otras especificaciones el cálculo tributario, las divisas y conversiones, el saldo pendiente, la edición y eliminación desde la interfaz, y la exportación de reportes.
- Se utiliza `America/Mexico_City` como zona de negocio para proponer y validar la fecha actual. La pertenencia a un mes se decide por la fecha de pago registrada, inclusive el primer día y exclusiva la fecha de inicio del mes siguiente.

- El origen del proyecto identifica cómo se consiguió el trabajo. La fuente del cobro identifica de quién o por qué canal llegó el ingreso. Se registra por cobro y permite distintas fuentes en un mismo proyecto. Cada pago se captura por separado y la cantidad recibida se calcula con los cobros guardados. El alcance incluye el conteo acordado sin fechas ni importes previstos por parcialidad. Los nombres de plataforma u otra fuente se capturan como texto en este incremento.
