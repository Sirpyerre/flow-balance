# Validación de formularios móviles y PWA

## Verificaciones ejecutadas

- `npm --prefix frontend run test:e2e` contra el entorno local HTTPS. Pasaron las dos pruebas de Chrome. A 360 px, registro e inicio de sesión tienen etiquetas y atributos de autocompletado y caben sin desplazamiento horizontal. Chrome recibió el service worker como JavaScript, la página quedó bajo su control y la navegación privada sin conexión mostró el aviso genérico.
- `npm --prefix frontend run build`. Una comprobación de navegador sirvió el build por HTTPS con la API de Herd detrás del mismo origen. Se verificaron inicio de sesión, tablero y reporte, ausencia de entradas privadas en Cache Storage, aviso sin conexión y cierre de sesión con invalidación en una segunda pestaña. Se comprobó el ancho de escritorio (1280 px) y móvil (360 px).

## Validaciones pendientes

La aceptación requiere comprobar instalación y reapertura en un celular, gestores de contraseñas reales, pegado y envío con Enter, y el recorrido de cambio de cuenta sin datos residuales. Sigue pendiente la evaluación de usabilidad con cinco participantes (SC-006).
