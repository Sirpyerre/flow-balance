# Guía de validación de Registro de ingresos

La guía se ejecuta una vez implementado el plan. Usar una base de pruebas independiente y HTTPS de Herd. Consultar la [configuración local](../../docs/local-development.md) y el [contrato](contracts/income.md).

## Preparar el entorno

1. Inicia Herd y PostgreSQL. Configura las variables `TEST_DB_*` de la base de pruebas existente.
2. Aplica las migraciones dos veces con `APP_ENV=testing herd composer db:migrate`. La segunda ejecución deja el esquema al día. Repite sobre una base con cobros anteriores y comprueba sus importes y totales.
3. Ejecuta `APP_ENV=testing herd composer test` con esa misma base. Para navegador, arranca `npm --prefix frontend run dev` con Herd y la API apuntando al entorno de pruebas. Ejecuta `npm --prefix frontend run test:e2e` contra el origen de pruebas.
4. Verifica la reversión en una base de pruebas vacía y reaplica. Comprueba que una reversión que descartaría datos capturados se rechaza hasta preservar esos datos.

## Recorridos

1. Con una cuenta vacía, crea «Estudio Norte» y el proyecto «Identidad visual» por MXN 5,000.00, origen «Recomendación» y tres pagos acordados. Confirma «0 de 3 pagos recibidos» y vuelve a seleccionar ese cliente para otro proyecto.
2. Desde el proyecto y a 360 px, registra MXN 3,000.00 el 30 de noviembre de 2026 de cliente directo, con comisión de MXN 150.25 e impuestos de MXN 49.75. Comprueba MXN 2,800.00 recibidos. Usa un reloj de pruebas posterior a las fechas del escenario.
3. Registra MXN 800.00 el 1 de diciembre de 2026 con fuente plataforma «Upwork». Confirma «2 de 3 pagos recibidos», el origen «Recomendación» y MXN 3,800.00 abonados en el proyecto, con monto acordado de MXN 5,000.00. Abre los dos reportes y verifica que cada monto mensual abre el cobro que lo compone.
4. Simula una respuesta perdida tras guardar y reintenta con la misma operación. Comprueba que existe un solo cobro. Envía la misma clave con un importe diferente y comprueba el conflicto. Registra intencionalmente otro pago con los mismos datos mediante una nueva captura.
5. Con una segunda cuenta, intenta consultar el cobro y registrar un pago en el proyecto ajeno. Debes recibir la misma respuesta que para recursos inexistentes y ver solo cobros propios en el reporte.
6. Verifica que un abono negativo, precisión superior a dos decimales y deducciones superiores al abono se rechazan. Comprueba que se conservan los demás datos y el caso de MXN 0.30 menos MXN 0.10 y MXN 0.20 devuelve MXN 0.00.
7. En la PWA, abre el detalle en línea y pierde conexión. Verifica que los datos privados se ocultan y que al recargar no quedan accesibles. Repite tras cerrar sesión o cambiar de cuenta. Sigue la [guía de Android por USB](../../docs/android-usb.md) para el dispositivo físico.

8. En un proyecto de MXN 5,000.00 con MXN 4,800.00 abonados, captura MXN 500.00. El aviso muestra MXN 300.00 de excedente. Guardar sin razón devuelve error y conserva los campos. Guarda con «Trabajo adicional» y verifica la razón en el detalle. Verifica también igualdad exacta sin razón, proyecto histórico sin monto acordado y conteo real por encima de lo acordado.
9. Con un proyecto de MXN 5,000.00 y MXN 4,800.00 abonados, previsualiza dos pagos de MXN 150.00 y envíalos con dos conexiones independientes. El primero aplicado llega a MXN 4,950.00. El otro requiere razón por MXN 100.00 de excedente y se guarda al corregir. Repite la primera solicitud y confirma que devuelve su cobro original. Comprueba que el PATCH heredado no puede aumentar un excedente. En otro caso, previsualiza MXN 300.00 de excedente y escribe una razón válida. Aplica otro cobro antes de enviar para elevar el excedente a MXN 600.00. Verifica que el guardado se confirma con MXN 600.00.
10. Desde el detalle del cobro de noviembre, registra MXN 50.00 de comisión el 2 de diciembre y MXN 25.00 de impuesto el 3 de diciembre. Confirma ambas fechas y que recibido y reporte de abonos se conservan. Simula un reintento equivalente, uno con datos distintos y un alta por otra cuenta. Comprueba respectivamente un solo cargo, conflicto y respuesta igual a la de un cobro inexistente.
11. Envía USD en un alta de proyecto, un registro de cobro y un registro de cargo. Cada solicitud debe fallar sin crear el registro. Verifica MXN explícito en cada importe mostrado.

## Respaldo y recuperación antes de revertir

1. Pausa las escrituras. Genera un respaldo completo en formato personalizado, con un usuario que pueda leer todas las tablas. Conserva el archivo en una ubicación privada.

   ```sh
   pg_dump -h HOST -p PUERTO -U USUARIO -d BASE_ORIGEN --format=custom --file=flow-balance-before-rollback.dump
   pg_restore --list flow-balance-before-rollback.dump
   ```

2. Restaura el respaldo en una base vacía independiente y verifica allí el historial de Phinx y los registros, incluyendo acuerdos, fuentes, deducciones, razones y cargos.

   ```sh
   pg_restore -h HOST -p PUERTO -U USUARIO -d BASE_RECUPERACION --exit-on-error flow-balance-before-rollback.dump
   ```

3. Prueba `APP_ENV=testing herd composer db:rollback` en una base de pruebas sin capturas y vuelve a aplicar con `APP_ENV=testing herd composer db:migrate`. Si la base tiene datos incorporados por esta fase, las migraciones rechazan la reversión. Mantén el esquema o prepara una migración nueva que preserve y transfiera esos datos.
4. Si necesitas recuperar el estado anterior a una intervención, valida el respaldo restaurado con la versión correspondiente de la aplicación antes de cambiar la conexión. Comprueba los totales mensuales en la base recuperada y reanuda las escrituras después de validar.
