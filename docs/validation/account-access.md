# Validación de registro, acceso y aislamiento

Se comprobó el ciclo de Phinx sobre una base PostgreSQL 17 exclusiva de pruebas.
La primera aplicación ejecutó cinco migraciones. La segunda no aplicó ninguna.
La última migración se revirtió en una base vacía y volvió a aplicarse.

La prueba de integración usa el pipeline real de Mezzio y PostgreSQL. Verificó
registro, tablero vacío, rechazo de correo duplicado, inicio con mayúsculas en
el correo, mensajes equivalentes para credenciales incorrectas y bloqueo desde
el quinto fallo. Los reportes devolvieron MXN 3,000.00 para Ana y MXN 800.00 para
Luis. La lectura y la modificación del cobro ajeno se rechazaron con 404. El
importe original se conservó. También se verificó la repetición de una solicitud
de registro de cobro con la misma clave y los mismos datos.

Resultados: cuatro pruebas unitarias con seis aserciones, una prueba de integración
con 59 aserciones y compilación de cuatro pantallas Astro. Quedan pendientes las
pruebas de navegador sobre HTTPS con Herd, la propagación confiable de IP del
cliente, la concurrencia y los restantes casos de aceptación registrados en
`tasks.md`.
