# Validación de usabilidad

## Preparación

- Usar Herd con el origen HTTPS local, Astro y PostgreSQL de pruebas. Consultar la [guía local](../../docs/local-development.md) y preparar el esquema de pruebas con `APP_ENV=testing php vendor/bin/phinx migrate`.
- Compilar con `npm --prefix frontend run build`. Ejecutar PHPUnit con la configuración de pruebas y Playwright con `npm --prefix frontend run test:e2e` apuntando a la instancia HTTPS de pruebas.

## Recorridos

1. A 1080 y 360 px comparar las nueve referencias. Cambiar de tema en landing y tablero, recargar y abrir la PWA. Revisar foco, contraste, zoom y navegación inferior.
2. Cargar siete cobros como en la referencia y paginar de dos en dos. Verificar el total general y el de noviembre, el mes sin cobros y un filtro con varias monedas. Abrir el selector, escribir un año lejano, cancelar con Escape y repetir aplicando el mes.
3. Crear un cliente desde el alta de proyecto y verificar el retorno con el formulario intacto. Probar un monto no divisible en centavos entre los pagos. Corregir un dato del alta y verificar que el guardado normal funciona. Con MXN 6,600.00 abonados, reducir el acuerdo a MXN 6,000.00. Verificar excedente de MXN 600.00, error sin razón y guardado con razón. Consultar el registro del ajuste en la base de pruebas y verificar que acuerdo y motivo concuerdan. Repetir el guardado con el mismo monto y comprobar que existe una sola revisión. Comprobar que un cobro registrado entre vista previa y guardado obliga a recalcular y que la reasignación de cliente sigue restringida. En un esquema de pruebas verificar migración, reversión y reaplicación sin ajustes y bloqueo con ajustes registrados.
4. Capturar un cobro MXN y otro USD, revisar neto y avance antes de guardar, repetir el guardado y comprobar que el registro se crea una vez. Editar, cancelar, corregir y registrar un cargo separado desde el detalle. Verificar versiones desactualizadas, rechazo de la reasignación por API y que el cargo separado solo se clasifica como Comisión o Impuesto y aparece según su propia fecha.
5. Con dos sesiones, comprobar aislamiento de resúmenes y escrituras. Cerrar sesión, probar pantalla anterior y revisar que la PWA no conserve datos privados disponibles sin sesión.
