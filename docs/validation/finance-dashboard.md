# Validación del tablero y acceso

## Línea base

La primera ejecución de Phinx en la base de pruebas confirmó que el esquema
estaba al día antes de la migración de entrega. Se conservaron los contratos
vigentes de tablero, detalle y reporte mensual. Las nuevas lecturas se
verificaron con sesión, pertenencia y la política privada sin caché.

## US1 · Tablero

La suite de navegador comprobó los tres cobros mensuales de la referencia, su
total de MXN 6,723.00, el saldo independiente de MXN 15,400.00, el filtro, el
selector con cero, uno y varios proyectos, los estados de cobro y la navegación
por dos páginas del historial. Se usaron vistas de 1,080 y 360 px. La integración
comprobó sumas exactas, orden estable y aislamiento del historial.

## US2 · Estado de entrega

Phinx aplicó `20261119000200` en PostgreSQL de pruebas. La prueba en un esquema
aislado verificó el valor nulo histórico, el valor En curso en altas nuevas, la
reversión y reaplicación y el bloqueo de reversión con estados registrados.
Se verificaron CSRF, pertenencia, error recuperable en la interfaz y conservación
de saldo y estado de cobro al cambiar el estado de entrega.

## US5 · IP efectiva

PHPUnit verificó resolución directa y confiable, normalización IPv4/IPv6 y la
separación de contadores en Mezzio y PostgreSQL. Un ingreso HTTPS de pruebas con
el certificado local de Herd encaminó solicitudes de dos orígenes reales
IPv4 e IPv6 a Astro en modo desarrollo y Mezzio. IPv4 recibió 429 en su quinto
fallo e IPv6 conservó su contador independiente. Queda pendiente repetir la
comprobación con el bloque Nginx de Herd de la máquina después de configurar
los saltos y el token de reenvío. La aplicación conserva el comportamiento por
dirección directa mientras esa configuración esté deshabilitada.

## US3 · Cobros en USD

PostgreSQL aplicó `20261119000300` en la base de pruebas. En un esquema
aislado se verificó la reversión y reaplicación, la conservación de un cobro
USD histórico y el bloqueo de reversión con una tasa capturada.

La API verificó el equivalente de USD 100.00 a 17.25, el redondeo de medio
centavo, el residual del impuesto, una deducción del 100 % y el rechazo de tasas
inválidas. Se probó la corrección en ambos sentidos, una edición de fecha que
conserva la tasa, el reintento con tasa equivalente, CSRF y pertenencia.
Un cobro histórico sin tasa se mantuvo visible y dejó su proyecto fuera del
saldo global hasta que se registró la tasa. El navegador verificó captura,
previsualización, edición y presentación del aviso provisional.

## US4 · Neto y CSV del periodo

El escenario de noviembre produjo MXN 1,350.00 netos. El impuesto separado de
MXN 75.00 se reflejó en diciembre como un neto de MXN -75.00. Se comprobó
la coincidencia del movimiento exportado con la respuesta JSON, la sesión
obligatoria, la política privada y el aislamiento entre cuentas. Tras eliminar
el cobro de origen, su cargo dejó de figurar en el reporte. El navegador
verificó los enlaces al cobro y descargó el archivo de diciembre.

## Resultados

PHPUnit pasó 25 pruebas con 455 aserciones y una segunda ejecución de Phinx
confirmó que el esquema de pruebas estaba al día. Astro compiló 11 páginas y
Playwright pasó 12 pruebas sobre HTTPS en Chrome.
Continúan pendientes la validación con el bloque Nginx local de Herd y las
comprobaciones manuales de cambio de cuenta con un cobro abierto, reapertura
de la PWA y gestores de contraseñas en un dispositivo Android físico.
