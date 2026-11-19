# Guía de validación de saldos

1. Prepara la base independiente y el origen HTTPS con la [guía local](../../docs/local-development.md). Aplica las migraciones existentes, ejecuta PHPUnit y corre Playwright contra el origen de pruebas.
2. Crea un proyecto de MXN 5,000.00. Confirma el saldo inicial de MXN 5,000.00 y registra MXN 3,000.00 y MXN 800.00 en meses consecutivos. Añade deducciones y un cargo separado. Comprueba MXN 1,200.00 en detalle y reporte y consulta cada abono desde el detalle.
3. Cambia de mes en el reporte de abonos. Comprueba MXN 3,000.00 y MXN 800.00 en sus respectivos periodos. El saldo vigente permanece en MXN 1,200.00.
4. Registra MXN 1,200.00 y verifica «Liquidado». Registra otro abono de MXN 300.00 con su razón y verifica el excedente separado. Reintenta con la misma clave y comprueba que los valores se conservan.
5. Consulta un proyecto histórico sin acuerdo y una cuenta sin proyectos. Con dos cuentas, verifica el aislamiento del reporte y el 404 uniforme del detalle ajeno. A 360 px, abre un proyecto y sus abonos. Desconecta y recarga el reporte para comprobar que los datos privados quedan ocultos.
6. Con proyectos en los cuatro estados, comprueba que «Pendientes» abre por defecto y «Todos» incluye los cuatro. Con proyectos y ninguno pendiente, verifica «No tienes proyectos con saldo pendiente». Una cuenta vacía muestra «Aún no tienes proyectos».

7. Con datos de prueba, verifica que dos abonos históricos de MXN 0.015 se muestran como MXN 0.02 cada uno en el proyecto y aportan MXN 0.04 al saldo. Comprueba que los importes almacenados se conservan.
8. Simula un fallo del reporte de saldos. Comprueba el reintento, la disponibilidad del reporte mensual y que una respuesta tardía se descarte al ocultar el tablero.
