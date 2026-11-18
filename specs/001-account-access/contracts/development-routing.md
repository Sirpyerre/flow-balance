# Contrato de enrutamiento local

## Trayecto de una petición

```text
Navegador / PWA
  https://flow-balance.test
    → Herd (TLS, proxy público)
      → Astro en http://127.0.0.1:4321
        /api/* → proxy Vite → http://flow-balance-api.test/api/*
                                    → Herd PHP 8.4 → public/index.php → Mezzio
        otras rutas → pantallas y recursos Astro
```

No quitar `/api` en el proxy. El backend registra las rutas con ese prefijo.
No se hace fetch desde el navegador a `flow-balance-api.test`.

## Configuración prevista de Astro

Fragmento de configuración, a integrar al implementar `frontend/astro.config.mjs`:

```js
const publicOrigin = process.env.APP_ORIGIN ?? 'https://flow-balance.test';
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://flow-balance-api.test';

export default defineConfig({
  site: publicOrigin,
  server: { host: '127.0.0.1', port: 4321 },
  vite: {
    server: {
      strictPort: true,
      allowedHosts: [new URL(publicOrigin).hostname],
      proxy: {
        '/api': {
          target: apiTarget,
          changeOrigin: true,
        },
      },
    },
  },
});
```

La integración React se agrega en el mismo archivo; este fragmento muestra solo el
contrato del proxy. Ajustar HMR a `wss://flow-balance.test:443` y verificar WebSocket
por Herd antes de aceptar el entorno. No usar `allowedHosts: true`.

`changeOrigin` cambia Host para Herd; no autoriza reescribir la cabecera `Origin`.
Preservar Cookie, cada Set-Cookie, estados y cabeceras no-store. No incluir `Domain`
en cookies ni reemplazar `Secure` porque el upstream de loopback use HTTP.

## Host, esquema e IP confiables

`APP_ORIGIN=https://flow-balance.test` es obligatorio; no se infiere del Host interno.
Herd público debe sobrescribir, no aceptar sin control, las cabeceras de cliente
`X-Forwarded-For`, `X-Forwarded-Host` y `X-Forwarded-Proto`. Astro transmite la cadena
verificada; el adaptador HTTP de Mezzio solo confía en los saltos locales configurados.
Se normaliza IPv4/IPv6 antes de obtener la clave del limitador.

El criterio de aceptación del proxy incluye enviar un `X-Forwarded-For` falsificado:
no debe cambiar la IP efectiva ni el contador de login. Si la configuración estándar
de Herd no ofrece esa garantía, se ajusta el virtual host local con cabeceras
sobrescritas antes de habilitar el escenario; no se acepta la cabecera por comodidad.
Sin cadena confiable se utiliza la IP del peer, nunca una IP arbitraria del navegador.

## Fuera de desarrollo

El proxy de entrada HTTPS sirve los archivos construidos por Astro, enruta `/api`
a Mezzio y aplica no-store a documentos privados. Mantiene el mismo origen público.
Vite no es servidor de producción; PostgreSQL nunca se expone al navegador.

Fuentes: [Vite](https://vite.dev/config/server-options),
[Astro y Vite](https://docs.astro.build/en/reference/configuration-reference/#vite),
[Herd CLI](https://herd.laravel.com/docs/macos/advanced-usage/herd-cli).
