# Plan técnico de Saldos pendientes por proyecto

**Entrada** — [Especificación](spec.md)  
**Artefactos** — [Decisiones](research.md), [modelo](data-model.md), [contrato](contracts/project-balances.md) y [validación](quickstart.md).

## Resumen

Extender el detalle existente con métricas de saldo y agregar al tablero un reporte vigente por proyecto. Las dos consultas comparten una regla de aplicación comprobable con importes decimales. Se conserva el reporte mensual existente.

## Contexto técnico

PHP y Laminas Mezzio para consultas privadas, PostgreSQL para persistencia, Astro para el tablero y React para el detalle existente. Las pruebas usan PHPUnit con PostgreSQL independiente y Playwright sobre HTTPS. Herd y Docker Compose conservan su configuración actual. El incremento deriva los importes al consultar y prescinde de cambios de esquema.

## Revisión constitucional

- **I y V.** Especificación y plan preceden a las tareas. La regla monetaria tendrá pruebas independientes del framework.
- **II, III y VIII.** Los importes son exactos en MXN. Acuerdo, abonado, saldo y excedente tienen campos propios. El estado de cobro se deriva independientemente de la entrega.
- **IV.** El reporte enlaza el detalle y sus cobros. Se conserva la idempotencia existente.
- **VI.** Se reutilizan sesión, autorización por cuenta, cabeceras privadas y protección sin conexión. Se valida el recorrido a 360 px.
- **VII, IX, X y XI.** Identificadores y comentarios en inglés, interfaz en español de México y una sola regla de saldo fuera de Mezzio, Astro y React.

## Diseño y orden de implementación

1. **Regla y pruebas unitarias.** Crear una regla de saldo en `src/Finance/Application/`. Recibir acuerdo opcional y suma abonada como cadenas decimales. Comparar y restar centavos mediante operaciones de cadenas sin conversión a tipos nativos de precisión limitada. Revisar las operaciones existentes de `PaymentAmounts` y reutilizar las que preserven totales por encima del límite de un cobro. Normalizar cada abono histórico con `round(client_paid_amount, 2)` en PostgreSQL antes de sumar. Las cantidades positivas a medio centavo se redondean hacia arriba. Compartir la proyección de importes entre las dos lecturas y conservar el valor almacenado.
2. **Detalle.** Obtener acuerdo, suma y cobros propios en una sola sentencia SQL para que el desglose corresponda al resultado. Reutilizar el lector de detalle y enriquecer su respuesta de forma aditiva. Añadir saldo, excedente y estado a la isla existente.
3. **Reporte.** Incorporar una consulta agrupada por proyecto, con unión izquierda a abonos, filtrada por titular y moneda. Resolver la regla de aplicación por fila y exponer una lectura privada. Ordenar por nombre e identificador. Cargar la sección del tablero con los controles de sesión y limpieza existentes. Mantener el selector de mes limitado al reporte mensual.
4. **Validación.** Ejecutar unitarias, integración y navegador. Comprobar estados, límites decimales, trazabilidad, aislamiento, consistencia de la lectura, datos históricos, cambio de mes, guardado idempotente y recarga sin conexión. Revisar la disposición móvil.

## Estructura afectada

El tablero filtra la lista en memoria por `collectionStatus`. Abre con «Pendientes» y permite cambiar a «Todos». La respuesta completa distingue una cuenta sin proyectos de una lista sin coincidencias. El filtro comparte el ciclo de vida de los datos privados del tablero. La sección distingue carga, éxito y error con reintento. El error de saldos es independiente del reporte mensual. Al ocultar o limpiar el tablero, cancelar la solicitud y comprobar su vigencia antes de actualizar la vista.

- `src/Finance/Application/` y `src/Finance/Infrastructure/` para regla y lecturas.
- `config/routes.php` y `src/Finance/Http/` para integrar las consultas protegidas.
- `frontend/src/pages/dashboard.astro`, `frontend/src/components/IncomePage.tsx` y tipos compartidos del frontend.
- `tests/Unit/`, `tests/Integration/` y `frontend/tests/`.

La revisión posterior al diseño mantiene los principios anteriores. La implementación se organiza después en tareas por historia.
