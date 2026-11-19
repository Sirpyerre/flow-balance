# Contratos de los incrementos

## Tablero y captura

- `GET /api/dashboard` mantiene su representación vigente y añade los contadores necesarios de clientes con proyectos y colecciones. `GET /api/reports/project-balances` añade `totalOutstandingBalance` como decimal en cadena en MXN y `pendingProjectCount` antes del filtro de interfaz. En el incremento de divisas, ambos excluyen proyectos que tengan cobros sin convertir. El agregado incluye `excludedUnconvertedProjectCount` con el número de proyectos excluidos por esta causa. El detalle del proyecto y cada fila de saldos exponen `unconvertedPaymentCount` y `isBalanceProvisional`. Si hay un saldo calculable y el conteo es positivo, la interfaz muestra el saldo provisional y «Hay cobros sin convertir». Si no hay acuerdo para calcularlo, se conserva «Saldo por determinar» con el aviso.
- `GET /api/reports/monthly?month=YYYY-MM&currency=MXN` alimenta tarjeta y panel del mes con la misma respuesta. El selector conserva la cuenta activa y descarta respuestas de una selección anterior. `GET /api/payments` pagina cobros activos propios con cursor por fecha e identificador descendentes. Cada enlace abre el detalle existente.
- El acceso superior «Registrar cobro» deriva a la creación del primer proyecto con cero proyectos, abre la captura existente con uno y con varios muestra todos los proyectos propios, incluso liquidados y con saldo por determinar. El grupo «Pendiente» aparece primero y cada opción muestra el estado de cobro. «Ver todos» lleva a una pantalla privada que consume el listado. Los casos vacío, carga y error permiten volver al estado anterior o reintentar sin perder el periodo.

## Entrega y divisas

- `GET /api/projects/{id}` añade `deliveryStatus`. `PATCH /api/projects/{id}` admite actualizar este campo con la sesión del titular y CSRF, conservando los demás datos.
- `POST /api/payments/preview` y `POST /api/payments` reciben `currency`, `clientPaidAmount`, `platformFee`, `withheldTaxAmount` y, para USD, `exchangeRate` como cadena decimal positiva de hasta seis decimales en MXN por USD. Comisión e impuestos descontados siguen `currency`. En MXN la tasa se omite o es nula. `POST /api/payments/{id}/charges` mantiene MXN y la fecha del cargo.
- `GET /api/payments/{id}` conserva los importes en la moneda original e incluye `exchangeRate`, `conversionPending` y los equivalentes canónicos en MXN. Un cobro sin conversión tiene equivalentes nulos y cuenta como cobro activo. Las sumas omiten sus importes sin convertir. Su saldo de proyecto se trata según el contrato del tablero.
- `PATCH /api/payments/{id}` conserva `If-Match` e `Idempotency-Key` para correcciones versionadas. Si cambia `currency` entre MXN y USD, el cuerpo incluye `confirmCurrencyChange: true`, el importe y ambas deducciones explícitas en la moneda de destino, incluso ceros. Al cambiar a USD exige la tasa nueva y al cambiar a MXN la tasa persistida queda nula. En una edición que conserve moneda, una tasa omitida conserva la vigente. Datos incompletos o inválidos devuelven 422. La representación actualizada conserva el identificador y los cargos y refleja la nueva versión. La revisión registra valores anteriores, incluida la moneda y la tasa.
- Las huellas de operaciones con conversión incluyen moneda y tasa normalizadas. Se preservan las huellas originales de alta, los reintentos de escrituras MXN heredadas y la compatibilidad del PATCH mínimo para cobros MXN. Repetir el alta de un cobro activo devuelve su identificador existente y su representación vigente. Los conflictos, reintentos de correcciones y bajas siguen el [contrato de corrección](../../004-correct-payments/contracts/payment-corrections.md).

## Reporte y exportación

- Se añaden comisiones, impuestos y neto a la respuesta mensual existente. Cada movimiento incluye fecha efectiva, clase, referencia al cobro e importes. Se distingue retención de cargo separado y se incluyen los cargos de otros meses según su propia fecha.
- `GET /api/reports/monthly/export?month=YYYY-MM&currency=MXN` devuelve CSV UTF-8 como descarga privada. Incluye fecha, concepto, referencia, importes originales, moneda, tasa y equivalentes. Las cadenas se escapan para abrirlas como datos en hojas de cálculo. La descarga utiliza los mismos filtros y lecturas del reporte y evita incluir atributos ajenos al movimiento.
- Lecturas y exportación devuelven 401 sin sesión, 404 uniforme para recursos ajenos, y cabeceras privadas sin caché. Las escrituras conservan los códigos funcionales y la política de CSRF.

## Acceso mediante proxy

El punto de entrada determina la dirección efectiva solo para una cadena de saltos configurada y pasa el valor normalizado al flujo de login. Con conexión o datos de reenvío sin confianza aplica la dirección directa disponible. La respuesta pública y la política actual de intentos permanecen compatibles.
