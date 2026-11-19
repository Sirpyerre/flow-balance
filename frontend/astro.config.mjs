import { defineConfig } from 'astro/config';
import { timingSafeEqual } from 'node:crypto';
import { isIP } from 'node:net';
import react from '@astrojs/react';
import { pwaIntegration, pwaDevPlugin } from './pwa.config.mjs';

const publicOrigin = new URL(process.env.APP_ORIGIN ?? 'https://flow-balance.test');
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://flow-balance-api.test';

const proxyToken = process.env.CLIENT_IP_PROXY_TOKEN ?? '';
const trustedIngress = (process.env.TRUSTED_ASTRO_INGRESS_PEERS ?? '').split(',').map(peer => peer.trim());
const normalizePeer = (address) => address?.startsWith('::ffff:') && isIP(address.slice(7)) === 4 ? address.slice(7) : address;

function apiProxy() {
  return {
    target: apiTarget,
    changeOrigin: true,
    configure(proxy) {
      proxy.on('proxyReq', (outgoing, incoming) => {
        outgoing.removeHeader('X-Flow-Client-IP');
        outgoing.removeHeader('X-Flow-Proxy-Token');
        if (!proxyToken) return;
        const peer = normalizePeer(incoming.socket.remoteAddress);
        const forwarded = incoming.headers['x-flow-client-ip'];
        const supplied = incoming.headers['x-flow-proxy-token'];
        const signature = typeof supplied === 'string' ? Buffer.from(supplied) : Buffer.alloc(0);
        const expected = Buffer.from(proxyToken);
        const trusted = trustedIngress.includes(peer) && signature.length === expected.length
          && timingSafeEqual(signature, expected);
        const client = trusted && typeof forwarded === 'string' && isIP(forwarded) ? forwarded : peer;
        if (!client || !isIP(client)) return;
        outgoing.setHeader('X-Flow-Client-IP', client);
        outgoing.setHeader('X-Flow-Proxy-Token', proxyToken);
      });
    },
  };
}

function configurePrivateCache(server) {
  server.middlewares.use((request, response, next) => {
    const path = request.url?.split('?')[0]?.replace(/\/$/, '');
    if (path === '/dashboard' || /^\/(?:clients|projects|payments)(?:\/|$)/.test(path ?? '')) {
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
    preview: { proxy: { '/api': apiProxy() }, allowedHosts: [publicOrigin.hostname] },
    server: {
      strictPort: true,
      allowedHosts: [publicOrigin.hostname],
      hmr: { protocol: 'wss', host: publicOrigin.hostname, clientPort: 443 },
      proxy: { '/api': apiProxy() },
    },
  },
});
