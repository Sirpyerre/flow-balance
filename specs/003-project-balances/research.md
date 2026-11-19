# Decisiones de diseño

| Decisión | Motivo |
| --- | --- |
| Derivar saldos al consultar | Las escrituras existentes de cobros siguen siendo la fuente de verdad. Una columna de saldo requeriría sincronización y migraciones. |
| Separar saldo vigente y reporte mensual | Un abono del mes anterior reduce lo que queda por cobrar. El reporte mensual sigue dando el importe abonado en su periodo. |
| Usar PostgreSQL para redondear por abono y sumar, y PHP para la regla compartida | La suma coincide con los importes visibles en el desglose. Los importes históricos almacenados se conservan. La regla de diferencia, excedente y estado se prueba fuera de HTTP y se comparte con el detalle. |
| Leer acuerdo y abonos del detalle en una instantánea | Un cobro concurrente puede entrar entre consultas. La cifra y los enlaces deben corresponder a los mismos datos. |
| Empezar con MXN y sin total general | Los acuerdos de esta fase están en MXN. Una marca explícita permite revisar proyectos históricos sin introducir cifras estimadas. |
