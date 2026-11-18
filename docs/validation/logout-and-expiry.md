# Cierre de sesión y vencimiento

Chrome sobre Herd HTTPS confirmó la secuencia de acceso al tablero, cierre con
respuesta 204, redirección al acceso en dos pestañas del mismo perfil y rechazo
de la entrada privada al primer proyecto.

PHPUnit verificó que un cierre sin CSRF devuelve 403, que el cierre válido devuelve
204 y que reutilizar la cookie revocada contra el estado de sesión, el tablero y
el reporte devuelve 401. Una sesión independiente sigue activa. La suite de
integración terminó con 66 aserciones. Las cuatro pruebas unitarias pasaron y
cubren, entre otros casos, los límites exactos de inactividad y duración absoluta.

Las validaciones automáticas del tablero y del reporte inicial no prolongan la
inactividad. El filtro de mes iniciado por el usuario sí puede renovarla.
Quedan pendientes las pruebas específicas de carrera, fallo de confirmación,
modo sin conexión y restauración del historial en el navegador.
