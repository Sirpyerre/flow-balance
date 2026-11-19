# Validación del MVP de Registro de ingresos

- Phinx aplicó las tres migraciones en PostgreSQL de pruebas y de desarrollo. La segunda ejecución en cada base confirmó el esquema al día.
- PHPUnit pasó 12 pruebas con 157 aserciones en una base independiente. Se comprobó el aislamiento por cuenta, el cambio de mes, la aritmética exacta, la conservación de registros históricos, la reaplicación de migraciones y el bloqueo de reversión con cargos existentes. Dos conexiones independientes verificaron que el registro concurrente vuelve a calcular el excedente y que el reintento recupera el cobro original.
- El frontend compiló y Playwright pasó tres pruebas en Chrome sobre HTTPS. El recorrido verificó el alta de cliente y proyecto a 360 px, el cálculo exacto, el doble envío con una sola solicitud, la recuperación de cobros y cargos tras perder sus respuestas, la corrección de precisión, la razón de excedente, la consulta del reporte a ancho de escritorio y la ocultación de datos al perder conexión. La prueba de PWA verificó también la navegación privada sin conexión.
- Herd devolvió HTTP 200 y `Cache-Control: private, no-store` para el tablero local.

## Validación pendiente

- Probar la PWA instalada en un Android físico.
- Ampliar la matriz automatizada para los casos todavía abiertos en el [listado de tareas](../../specs/002-register-income/tasks.md), incluido el cambio de cuenta con un detalle abierto.
- Comprobar la conservación del estado «Entregado» cuando el proyecto incorpore ese campo. La prueba actual verifica que se conservan el cliente, el nombre y las condiciones acordadas del proyecto al registrar cobros.
