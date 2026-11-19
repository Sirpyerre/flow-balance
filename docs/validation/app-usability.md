# Validación de usabilidad de Flow Balance

Validación del incremento 006 en Chrome sobre HTTPS local, con PostgreSQL
`flow_balance_test`. Las cuentas y operaciones de prueba se ejecutaron con una
configuración independiente de la base de desarrollo.

## Estado guardado

El incremento 006 reúne la navegación privada compartida, los temas claro y
oscuro, el tablero adaptable y los listados de cobros, clientes y proyectos.
El historial muestra totales por moneda para todo el periodo filtrado, incluso
cuando hay varias páginas. El tablero y el historial comparten el selector de
mes y año.

Desde el alta de un proyecto se puede crear un cliente y continuar con el
borrador intacto. La edición del acuerdo exige y registra un motivo cuando una
reducción deja un excedente. Los formularios de cobro muestran el avance, el
neto y el saldo proyectado. La edición conserva el proyecto original y el
detalle permite registrar comisiones e impuestos pagados por separado.

En el ajuste posterior del tablero, se fijaron las dimensiones del ícono de
calendario y se cambió a un trazo sin relleno. La prueba de regresión comprueba
que el selector conserva su altura en móvil y queda separado del botón
«Registrar cobro» en escritorio.

Consulta la [especificación del incremento](../../specs/006-improve-app-usability/spec.md)
y su [lista de tareas](../../specs/006-improve-app-usability/tasks.md).

## Línea base y resultados

La validación del incremento anterior registró 25 pruebas de PHPUnit y 12
recorridos de Playwright sobre HTTPS. Antes de los cambios, PHPUnit confirmó
25 pruebas con 455 aserciones. Tras el incremento, Astro compiló 14 páginas,
PHPUnit pasó 25 pruebas con 501 aserciones y Playwright pasó 13 pruebas en
Chrome sobre HTTPS. Después del ajuste del ícono, se volvió a compilar Astro
y pasó la prueba específica del tablero en Chrome a 360 y 1080 px.

## Evidencia por historia

| Historia | Recorridos verificados |
| --- | --- |
| US1 · Navegación y acciones | Navegación privada y sección activa. Confirmación al cancelar un borrador. Edición y eliminación con fecha e importe, cancelación y conflicto de versión. |
| US2 · Tema | Oscuro inicial, cambio a claro desde la landing, persistencia al recargar y entrar al tablero, recuperación de oscuro desde la navegación privada. |
| US3 · Historial y periodos | Siete cobros suman MXN 24,323.00. Noviembre suma MXN 6,723.00 en páginas de dos filas. También se verificaron monedas mixtas, mes vacío, cursor incompatible y aislamiento. Aplicar un mes histórico actualiza los datos. Escape conserva el filtro y devuelve el foco. Una respuesta tardía de otro mes conserva los datos del filtro activo. |
| US4 · Clientes y acuerdos | Alta de cliente dentro del proyecto, conservación de borrador y cálculo de MXN 33.33 al dividir MXN 100.00 entre tres. Edición de un proyecto, excedente recalculado después de un nuevo cobro, rechazo sin razón y una sola revisión al repetir el ajuste. También se verificaron la restricción de reasignación y el bloqueo que comparten acuerdo y captura. |
| US5 · Cobros | Captura con selección o preselección de proyecto y conservación del borrador al cambiarlo. Proyección del neto y saldo de alta y de edición. Identidad y versión para la vista previa de edición. Conversiones USD, doble guardado, corrección, baja y cargos por su propia fecha. |
| US6 · Móvil | Recorrido a 360 px con navegación inferior, alta y edición, listado con cliente y controles de historial. También se comprobaron el tablero a 1080 px, la descarga CSV y la respuesta sin datos privados al navegar sin conexión. |

Las pruebas de interfaz están en `frontend/tests/app-usability.spec.ts` y las
regresiones de tablero, registro, conversión, correcciones y privacidad de la PWA.
Las pruebas de cuenta y migraciones están en `tests/Integration`.

## Migración

`20261119000400_create_project_agreement_revisions.php` se aplicó en pruebas.
La segunda ejecución de Phinx dejó el esquema al día. Una prueba aplica,
revierte y vuelve a aplicar la migración en un esquema aislado. También
comprueba la restricción de pertenencia y que la reversión se detiene si hay
revisiones registradas. Antes de revertir un entorno con revisiones, se requiere
exportar sus registros y confirmar su restauración. La base de desarrollo
queda pendiente de migrar con `herd composer db:migrate` en el entorno local.

## Validación manual pendiente

- Reabrir la PWA instalada en un teléfono físico y comprobar que conserva el tema.
- Revisar las nueve referencias en ambos temas y repetir el recorrido a 200 % de zoom.
