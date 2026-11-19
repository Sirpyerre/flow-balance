# Decisiones de diseño

| Decisión | Motivo | Alternativa evaluada |
| --- | --- | --- |
| Rediseñar sobre las lecturas existentes y agregar paginación únicamente a la lista completa | El tablero puede entregarse con datos reales antes de las migraciones de entrega y conversión | Esperar a que exista un único recurso agregado |
| Usar fecha de pago para el mes y conservar saldos vigentes | Mantiene la trazabilidad y el comportamiento aprobado del filtro mensual | Calcular un saldo histórico al cierre del mes |
| Usar MXN como moneda de cuenta y USD como primera moneda adicional, con tasa manual | Preserva el alcance del proyecto y deja constancia de la tasa realmente aplicada | Usar cotizaciones automáticas o acuerdos en moneda extranjera |
| Redondear comisión y deducciones acumuladas y asignar el residual al impuesto | Evita un neto equivalente negativo por redondeo en cobros totalmente retenidos y conserva la suma contable | Redondear cada componente de forma independiente |
| Modelar los cargos separados por fecha de cargo | Permite explicar meses con cargos sin abonos y conciliar el reporte | Imputarlos al mes del cobro original |
| Compartir filtros y proyección entre reporte y CSV | Evita discrepancias por moneda, fecha o pertenencia | Recalcular el archivo en el navegador |
| Resolver IP en la frontera HTTP mediante configuración explícita | La política de intentos permanece independiente de Herd y Astro | Usar metadatos de reenvío sin validar el salto directo |
