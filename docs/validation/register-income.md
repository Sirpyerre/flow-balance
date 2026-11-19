# Validación del MVP de Registro de ingresos

- Phinx aplicó las tres migraciones en PostgreSQL de pruebas y de desarrollo. La segunda ejecución en cada base confirmó el esquema al día.
- PHPUnit pasó 12 pruebas con 201 aserciones en una base independiente. Se comprobó el aislamiento por cuenta, el cambio de mes, la aritmética exacta, la conservación de registros históricos, la reaplicación de migraciones y el bloqueo de reversión con origen, número de pagos acordados, motivo de excedente o cargos existentes. Dos conexiones independientes verificaron que el registro concurrente vuelve a calcular el excedente y que el reintento recupera el cobro original.
- El frontend compiló y Playwright pasó tres pruebas en Chrome sobre HTTPS. El recorrido verificó el alta de cliente y proyecto a 360 px, el cálculo exacto, el doble envío con una sola solicitud, la recuperación de cobros y cargos tras perder sus respuestas, la corrección de precisión, la razón de excedente, la consulta del reporte a ancho de escritorio y la reutilización de un cliente en un segundo proyecto y la ausencia de datos privados al perder conexión y recargar. La prueba de PWA verificó también la navegación privada sin conexión.
- Herd devolvió HTTP 200 y `Cache-Control: private, no-store` para el tablero local.

## Aceptación funcional

El usuario confirmó el recorrido de escritorio con alta de cliente y proyecto, registro de cobro y cargo separado, y consulta del reporte mensual con acceso al detalle.

## Validación diferida

- Validación en Android físico y cambio de cuenta con un cobro abierto, diferidos por decisión del usuario.
- Ampliar la matriz automatizada para los casos todavía abiertos en el [listado de tareas](../../specs/002-register-income/tasks.md).
- Comprobar la conservación del estado «Entregado» cuando el proyecto incorpore ese campo. La prueba actual verifica que se conservan el cliente, el nombre y las condiciones acordadas del proyecto al registrar cobros.
