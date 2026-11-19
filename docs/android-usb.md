# Probar Flow Balance en Android por USB

## Vista móvil desde Astro

1. Inicia Astro desde la raíz del repositorio con `npm --prefix frontend run dev`. Para consultar la API, deja Herd y PostgreSQL activos.
2. Activa **Depuración USB** en las opciones para desarrolladores de Android. Conecta el teléfono a la Mac con un cable de datos y acepta la autorización en el dispositivo.
3. En Chrome de la Mac, abre `chrome://inspect/#devices`. Activa **Discover USB devices** y espera a que aparezca el Android.
4. Abre **Port forwarding**. En **Port** escribe `4321` y en **IP address and port** escribe `127.0.0.1:4321`. Activa **Enable port forwarding** y pulsa **Done**.
5. Abre **`http://localhost:4321`** en Chrome del Android. Puedes revisar la interfaz, la navegación y el control de visibilidad de contraseña mientras el USB esté conectado.

El reenvío lleva la conexión al puerto de Astro y conserva el protocolo HTTP de ese puerto. La URL con `https://localhost:4321` solo funcionaría con un servidor TLS configurado para ese nombre y puerto. [Guía de reenvío USB de Chrome DevTools](https://developer.chrome.com/docs/devtools/remote-debugging/local-server/)

## Sesión y PWA con el certificado de Herd

La prueba completa usa el origen `https://flow-balance.test`. Mezzio valida ese origen al registrar una cuenta o iniciar sesión. El certificado de Herd corresponde a `flow-balance.test`.

1. Mantén Herd sirviendo `https://flow-balance.test` en la Mac y Astro detrás del proxy.
2. Configura un proxy HTTP local que admita túneles HTTPS mediante CONNECT y resuelva `flow-balance.test` como lo hace la Mac. Reenvía por USB un puerto del Android al puerto de ese proxy, siguiendo la sección de dominios personalizados de Chrome. Configura temporalmente el proxy del Wi-Fi del Android con host `localhost` y el puerto reenviado. [Guía de Chrome para dominios personalizados](https://developer.chrome.com/docs/devtools/remote-debugging/local-server/#map-to-custom-local-domains)
3. Instala en Android únicamente el certificado público de la autoridad de Herd. En macOS suele estar en `~/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem`. El archivo `.key` debe permanecer en la Mac.
4. Abre `https://flow-balance.test` en Chrome del teléfono. Cuando cargue con el certificado reconocido, inicia sesión con la [cuenta de prueba](local-development.md#cuenta-de-prueba). Instala desde el menú de Chrome y vuelve a abrir desde el ícono. [Guía de instalación en Chrome](https://support.google.com/chrome/answer/9658361?co=GENIE.Platform%3DAndroid)
5. Al terminar, restaura el proxy del Wi-Fi del teléfono y desactiva el reenvío USB.

La ruta USB con proxy y certificado queda documentada para la prueba en un Android físico.
