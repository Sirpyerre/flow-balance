# Especificación de Saldos pendientes por proyecto

**Estado** — Borrador para revisión

Una persona freelance necesita saber cuánto queda por cobrar de cada proyecto y comprobar qué abonos explican el saldo.

## Aclaraciones

### Sesión 2026-11-18

- Q: ¿Qué proyectos debe mostrar el reporte de saldos al abrirlo? → A: «Pendientes» por defecto, con un filtro para cambiar a «Todos».
- Q: ¿Cómo se tratan las fracciones históricas de centavo en el saldo? → A: Redondear cada abono al centavo más cercano antes de sumarlo. En caso de empate, redondear hacia el centavo superior.

## Escenarios y pruebas de usuario

### US1 · Consultar el saldo en el detalle (P1)

Como profesional freelance, quiero ver lo acordado, lo abonado y el saldo vigente cuando abro un proyecto.

1. **Dado** un acuerdo de MXN 5,000.00, **cuando** hay un abono de MXN 3,000.00 con MXN 200.00 en deducciones, **entonces** el detalle muestra MXN 2,000.00 pendientes. Un cargo separado tampoco cambia ese saldo.
2. **Dado** un proyecto con acuerdo y sin abonos, **cuando** consulto su detalle, **entonces** el saldo pendiente equivale al monto acordado.
3. **Dado** un acuerdo de MXN 5,000.00, **cuando** el total abonado llega a MXN 5,000.00, **entonces** veo «Liquidado» y MXN 0.00 pendientes. Al llegar a MXN 5,300.00, veo MXN 0.00 pendientes y un excedente separado de MXN 300.00.
4. **Dado** un proyecto histórico sin monto acordado, **cuando** lo consulto, **entonces** veo los abonos registrados y «Saldo por determinar» sin una cifra de saldo.

### US2 · Revisar saldos de mi cuenta (P1)

Como profesional freelance, quiero revisar los saldos de mis proyectos desde el tablero y abrir cada detalle para consultar los abonos.

1. **Dado** que pagaron MXN 3,000.00 en noviembre y MXN 800.00 en diciembre por un proyecto de MXN 5,000.00, **cuando** cambio el mes del reporte de abonos, **entonces** el saldo vigente del proyecto permanece en MXN 1,200.00 y cada reporte mensual conserva sus propios abonos.
2. **Dadas** dos cuentas con proyectos, **cuando** reviso mi reporte de saldos, **entonces** solo veo mis proyectos. Un enlace a un proyecto ajeno se trata como uno inexistente.
3. **Dada** una cuenta sin proyectos, **cuando** abro el reporte, **entonces** veo «Aún no tienes proyectos» y puedo ir al alta. Al perder conexión, el reporte se oculta y una recarga sin conexión no muestra datos privados.
4. **Dada** una cuenta con proyectos pendientes, liquidados, con excedente y sin acuerdo, **cuando** abro el reporte, **entonces** veo solo los pendientes. Al seleccionar «Todos» veo los cuatro estados. Si tengo proyectos pero ninguno con saldo pendiente, la vista inicial muestra «No tienes proyectos con saldo pendiente» y permite cambiar a «Todos».

## Requisitos

- **FR-001.** Calcular el saldo vigente como el mayor entre cero y la diferencia entre el monto acordado y los abonos del proyecto en MXN. Calcular el excedente por separado como el mayor entre cero y la diferencia inversa.
- **FR-002.** Mostrar los importes en MXN con dos decimales. Para el saldo, redondear cada abono histórico al centavo más cercano antes de sumarlo, con empates hacia el centavo superior. Aplicar la misma presentación al desglose del proyecto y conservar el importe almacenado. Usar los importes abonados por el cliente. Las deducciones, los cargos separados y el dinero recibido conservan su propio significado. La regla tampoco depende del estado de entrega ni del número de pagos previstos.
- **FR-003.** Distinguir «Pendiente», «Liquidado», «Con excedente» y «Saldo por determinar». En el último caso, mostrar el total abonado y mantener indeterminados el saldo y el excedente.
- **FR-004.** Aplicar la misma regla al detalle y al reporte vigente por proyecto. Incluir proyectos sin abonos y proyectos sin acuerdo. Cada fila permite abrir el detalle y seguir los enlaces a sus abonos.
- **FR-005.** Incorporar los abonos persistidos al volver a consultar. Un reintento idempotente conserva el saldo resultante de un solo cobro.
- **FR-006.** Mantener independiente el reporte mensual de abonos. El reporte de saldos abarca todos los abonos registrados en MXN y se identifica como vigente.
- **FR-007.** Proteger ambas consultas por cuenta y sesión, conservar la privacidad sin conexión y ofrecer lectura en escritorio y móvil en español de México.
- **FR-008.** Abrir el reporte con el filtro «Pendientes», que incluye solo proyectos con saldo positivo. «Todos» incluye todos los estados. Distinguir una cuenta sin proyectos de una cuenta con proyectos que no coinciden con el filtro.

- **FR-009.** Mostrar un indicador durante la carga. Mostrar los mensajes de lista vacía solo tras una respuesta exitosa. Si falla la consulta de saldos, ofrecer «Reintentar» y mantener disponible el reporte mensual. Descartar respuestas de saldos que lleguen tras limpiar u ocultar el tablero.

## Entidades principales

- **Proyecto** con titular, monto acordado opcional y abonos asociados.
- **Saldo del proyecto** derivado del acuerdo y los abonos, con saldo pendiente, excedente y estado de cobro.

## Criterios de éxito

- **SC-001.** Los cuatro casos de US1 producen el importe y estado esperados. MXN 0.30 de acuerdo menos MXN 0.10 y MXN 0.20 de abonos da MXN 0.00. Dos abonos históricos de MXN 0.015 se muestran como MXN 0.02 cada uno y aportan MXN 0.04 al saldo.
- **SC-002.** Detalle y reporte coinciden para el mismo proyecto y los mismos datos. La transición de mes produce el resultado esperado de US2 y cada fila lleva al detalle correcto.
- **SC-003.** Dos cuentas solo ven sus propios datos. A 360 px se pueden leer importes y abrir un detalle sin desplazamiento horizontal. Una recarga sin conexión no muestra datos privados.

## Supuestos y alcance

El incremento entrega un reporte vigente por proyecto, sin total general. Quedan para otras fases los cortes históricos, vencimientos, conversiones, edición de acuerdos y estado de entrega. Los cobros válidos existentes siguen siendo la fuente de datos. El reporte mensual conserva su política de importes en esta fase.
