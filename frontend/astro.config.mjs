import { defineConfig } from 'astro/config';
import react from '@astrojs/react';
import { pwaIntegration, pwaDevPlugin } from './pwa.config.mjs';

const publicOrigin = new URL(process.env.APP_ORIGIN ?? 'https://flow-balance.test');
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://flow-balance-api.test';

function configurePrivateCache(server) {
  server.middlewares.use((request, response, next) => {
    const path = request.url?.split('?')[0]?.replace(/\/$/, '');
    if (path === '/dashboard' || path === '/clients/new' || /^(?:\/projects\/|\/payments\/)/.test(path ?? '')) {
      response.setHeader('Cache-Control', 'private, no-store');
    } else if (path === '/service-worker.js') {
      response.setHeader('Cache-Control', 'no-cache');
    }
    next();
  });
}

export default defineConfig({
  site: publicOrigin.origin,
  integrations: [react(), pwaIntegration],
  server: { host: '127.0.0.1', port: 4321 },
  vite: {
    optimizeDeps: { esbuildOptions: { define: { 'process.env.NODE_ENV': '"development"' } } },
    plugins: [pwaDevPlugin, {
      name: 'private-document-cache-policy',
      configureServer: configurePrivateCache,
      configurePreviewServer: configurePrivateCache,
    }],
    preview: { proxy: { '/api': { target: apiTarget, changeOrigin: true } }, allowedHosts: [publicOrigin.hostname] },
    server: {
      strictPort: true,
      allowedHosts: [publicOrigin.hostname],
      hmr: { protocol: 'wss', host: publicOrigin.hostname, clientPort: 443 },
      proxy: { '/api': { target: apiTarget, changeOrigin: true } },
    },
  },
});
