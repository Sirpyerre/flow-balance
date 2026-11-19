# Plan técnico de evolución financiera y tablero

## Resumen

Entregar cinco incrementos verificables sobre los flujos de Flow Balance. El tablero se puede publicar sobre los datos actuales. Entrega de proyectos y enrutamiento de acceso siguen de forma independiente. La conversión de cobros precede al reporte neto con exportación.

## Contexto técnico

Astro y React para la web y la PWA. PHP con Laminas Mezzio para la API del mismo origen, PostgreSQL y Phinx, PHPUnit y Playwright. Herd sirve PHP y Docker Compose ejecuta PostgreSQL local. El CSS usa los tokens existentes y la referencia adjunta. Se conserva la sesión de servidor, CSRF, el aislamiento por cuenta y la caché privada deshabilitada. Las pruebas escriben en PostgreSQL independiente.

## Revisión constitucional

- Spec Kit en español, código, SQL, pruebas y comentarios en inglés, interfaz en español de México.
- Los cálculos monetarios reutilizan las reglas de saldos y equivalencias en servicios comprobables fuera de HTTP y React. PostgreSQL usa decimales exactos y la API representa importes como cadenas.
- Entrega, estado de cobro, abono, cargos, retenciones y neto conservan significado propio. Los reportes enlazan movimientos a cobros activos y preservan la idempotencia y el historial.
- Migraciones nuevas y aditivas. Para una reversión que afecte datos nuevos se verifica que su conservación sea posible antes de ejecutar la operación.
- La revisión posterior al diseño cubre sesión, pertenencia, privacidad en PWA, límites de redondeo y ventanas mensuales. Cada incremento tiene un criterio de salida y una comprobación a 360 px para los flujos que modifica.

## Estructura del proyecto

- [Especificación](spec.md) y [referencia visual](dashboard-reference.png).
- [Decisiones](research.md), [modelo de datos](data-model.md), [contratos](contracts/increments.md) y [guía de validación](quickstart.md).
- Frontend en `frontend/src/pages/`, `frontend/src/components/`, `frontend/src/lib/` y `frontend/src/styles/theme.css`.
- Backend en `src/Finance/`, `src/Identity/`, `config/` y `db/migrations/`. Pruebas en `tests/` y `frontend/tests/`.

## Secuencia incremental

| Orden | Alcance y trabajo técnico | Criterio de salida |
| --- | --- | --- |
| 1 · US1 | Rediseñar `dashboard.astro` y reutilizar los GET de tablero, mensual y saldos. Incorporar resumen vigente en el backend y listado paginado de cobros. El selector usa enlaces a la captura existente, lista todos los proyectos propios y muestra primero los pendientes. Mantener una sola fuente por cifra. | Datos de la referencia, navegación, estados vacíos, carga y error, escritorio y 360 px. Sin migración. |
| 2 · US2 | Agregar campo nullable de entrega con restricción, inicializar altas nuevas en curso y conservar los históricos sin dato. Ampliar detalle y edición del proyecto con autorización, CSRF y validación. | Las dos combinaciones entrega/cobro y el estado histórico funcionan. Un cambio ajeno se rechaza y el saldo sigue igual. |
| 3 · US5 | Resolver la IP efectiva en la frontera HTTP, con configuración explícita de saltos y un valor tipado para el limitador. Documentar y verificar las topologías directa y Astro/Herd, incluida la configuración del proxy de entrada. | La política existente y la separación de dos orígenes funcionan mediante HTTPS. Puede avanzar en paralelo con los incrementos 1 y 2. |
| 4 · US3 | Agregar los datos de conversión y su auditoría con Phinx. Extender captura, previsualización, idempotencia, corrección y detalle. Incorporar una única proyección de importe computable en MXN en saldo, excedente y reportes. | Los cobros MXN existentes concuerdan con la línea base. Las transiciones MXN ↔ USD quedan auditadas. Los cobros sin convertir generan saldos provisionales y sus proyectos quedan fuera del total global. |
| 5 · US4 | Generar movimientos por fecha efectiva, agregar el neto y los conceptos al reporte mensual y exportar CSV desde la misma consulta y reglas de agregación. Mantener el campo de abonado actual compatible con el tablero. | Los totales concuerdan con su desglose y el CSV. Se cubren cargos en otro mes, neto negativo y aislamiento. |

Orden de producto recomendado 1 → 2 → 4 → 5. El incremento 3 tiene entrega propia. Cada incremento pasa su conjunto de pruebas y las regresiones de las fases anteriores antes de incorporarse. El primer entregable visible es el tablero con los flujos existentes y los controles funcionales.

## Diseño del tablero

- Escritorio con fondo oscuro, tarjetas de borde tenue, acento violeta a rosa, cabecera con fecha y acciones alineadas a la derecha. Fila superior 2:1:1, zona media 7:5 y fila inferior de tres columnas iguales. Tipografía, radios y estados parten del tema existente. Los datos se obtienen de la sesión activa.
- Móvil con tarjetas en una columna, cabecera que refluye, control de mes con etiqueta accesible y acciones de tamaño táctil. La barra de avance incluye una alternativa textual. La lista mensual y el filtro muestran estados de error y permiten reintentar su propia lectura.
- Usar el selector de mes con nombres en español de México y valor canónico `YYYY-MM`. «Abonado este mes» aparece en el mes actual. Para otros meses se usa el nombre del periodo. El saldo vigente se mantiene independiente. Los importes formateados conservan dos decimales.

- «Registrar cobro» lleva a la creación del primer proyecto si la cuenta no tiene ninguno, abre la captura directamente si tiene uno y muestra el selector si tiene varios. Cada proyecto muestra su estado de cobro. El grupo pendiente aparece primero y el resto sigue por nombre con desempate por identificador. El filtro de saldos y el mes seleccionado operan de forma independiente del selector.

## Conversión y corrección de moneda

- El abono, la comisión y los impuestos descontados comparten moneda. Los cargos separados conservan MXN y su fecha, incluso si se corrige la moneda del abono.
- La proyección compartida calcula los equivalentes según FR-07. Si falta la tasa, conserva el conteo del cobro y marca el saldo calculable como provisional. El detalle y el reporte de saldos identifican esos proyectos. El backend los excluye del importe global y del conteo de pendientes y devuelve un conteo de exclusiones. Tras convertir el último cobro faltante, el proyecto vuelve al agregado si tiene saldo positivo.
- En la corrección, confirmar explícitamente los importes de destino y exigir una tasa nueva al pasar a USD. Al pasar a MXN, guardar la tasa como nula. Recalcular proyección, saldo y excedente y aplicar la regla vigente del motivo de excedente. Conservar el identificador, los cargos y la huella de alta. Guardar el cambio y su revisión en una sola transacción, con versión esperada y moneda, importes y tasa anteriores en el historial.
- La huella de una nueva escritura distingue moneda y tasa normalizadas. Mantener la comparación con huellas MXN existentes y la compatibilidad de las escrituras anteriores. Un reintento de alta recupera el mismo cobro activo aunque haya cambiado su moneda desde el alta. Un reintento de corrección mantiene las condiciones del contrato de versiones.

## Complejidad y liberación

La conversión cruza múltiples lecturas y escrituras. Se entrega como un incremento completo con previsualización, persistencia, corrección y agregados coherentes, habilitado después de aplicar su migración. Antes de la liberación conjunta, repetir las pruebas diferidas de cambio de cuenta y PWA física en un ambiente HTTPS. Registrar resultados y pendientes de la fase de acceso en la bitácora.
