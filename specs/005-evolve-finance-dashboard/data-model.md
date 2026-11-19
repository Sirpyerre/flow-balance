# Modelo de datos

| Entidad | Campos y reglas |
| --- | --- |
| Proyecto | `deliveryStatus` independiente de `collectionStatus`. Valores almacenados `in_progress`, `delivered` o nulo para históricos. Un proyecto nuevo nace en curso. |
| Cobro | Mantiene titular, proyecto, importe y moneda originales, deducciones, fecha, versión y estado activo. `exchangeRate` decimal positivo de hasta seis decimales para USD. MXN usa equivalencia 1 y almacena la tasa como nula. Deducciones descontadas y abono comparten moneda. Los cargos separados conservan MXN. Los USD históricos con tasa nula quedan identificados. |
| Conversión | Equivalentes en MXN derivados del importe original y la tasa persistida. Comisión y deducciones acumuladas se redondean al centavo. El impuesto equivalente absorbe el residual, el neto se obtiene por diferencia. La auditoría conserva moneda, importes, tasa, versión anterior y actor. Cambiar de moneda conserva identidad, huella de alta y cargos separados. |
| Movimiento | Abono con deducciones en su fecha de pago o cargo separado en su fecha efectiva. Conserva referencia al cobro activo, categoría y original/equivalente según aplique. |
| Resumen vigente | Suma exacta de saldos positivos determinados y conteo de proyectos que los componen. Un proyecto con cobros activos sin equivalente queda fuera de ambos y aporta al conteo de exclusiones. Los clientes activos son clientes con al menos un proyecto de la cuenta. |
| Origen del intento | IP de conexión o IP efectiva de una cadena autorizada. Se mantiene la clave protegida del limitador existente. |

Las cifras canónicas en MXN se calculan con la misma proyección en detalle, saldos, excedentes y reportes. El conteo de cobros activos incluye históricos sin conversión. Si el proyecto tiene saldo calculable, se presenta como provisional mientras tenga al menos uno de estos cobros. El reporte identifica los movimientos sin equivalente y los excluye de sus sumas en MXN. Los datos anteriores se mantienen y cualquier reversión que perdería estados o tasas guardados se bloquea hasta preservar esos datos.
