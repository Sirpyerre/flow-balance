import { defineConfig } from 'astro/config';
import react from '@astrojs/react';

const publicOrigin = new URL(process.env.APP_ORIGIN ?? 'https://flow-balance.test');
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://flow-balance-api.test';

export default defineConfig({
  site: publicOrigin.origin,
  integrations: [react()],
  server: { host: '127.0.0.1', port: 4321 },
  vite: {
    plugins: [{
      name: 'private-document-cache-policy',
      configureServer(server) {
        server.middlewares.use((request, response, next) => {
          const path = request.url?.split('?')[0]?.replace(/\/$/, '');
          if (path === '/dashboard' || path === '/projects/new') {
            response.setHeader('Cache-Control', 'private, no-store');
          }
          next();
        });
      },
    }],
    server: {
      strictPort: true,
      allowedHosts: [publicOrigin.hostname],
      hmr: { protocol: 'wss', host: publicOrigin.hostname, clientPort: 443 },
      proxy: { '/api': { target: apiTarget, changeOrigin: true } },
    },
  },
});
