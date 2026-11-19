import { createHash } from 'node:crypto';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import { transformWithEsbuild } from 'vite';

const workerSource = new URL('./src/service-worker.ts', import.meta.url);
const publicFiles = ['/manifest.webmanifest', '/icons/app-192.png', '/icons/app-512.png'];

async function compileWorker(assets) {
  const source = await readFile(workerSource, 'utf8');
  const paths = JSON.stringify(assets);
  const version = createHash('sha256').update(source).update(paths).digest('hex').slice(0, 16);
  const result = await transformWithEsbuild(source, 'service-worker.ts', {
    loader: 'ts',
    target: 'es2022',
    define: { PUBLIC_ASSETS: paths, CACHE_VERSION: JSON.stringify(version) },
  });
  return result.code;
}

export const pwaIntegration = {
  name: 'flow-balance-pwa',
  hooks: {
    'astro:build:done': async ({ dir }) => {
      const files = await readdir(new URL('_astro/', dir));
      const assets = files.filter((file) => /\.(js|css|woff2?)$/.test(file)).sort();
      const worker = await compileWorker([...publicFiles, ...assets.map((file) => '/_astro/' + file)]);
      await writeFile(new URL('service-worker.js', dir), worker);
    },
  },
};

export const pwaDevPlugin = {
  name: 'flow-balance-pwa-development',
  configureServer(server) {
    server.middlewares.use(async (request, response, next) => {
      if (request.url?.split('?')[0] !== '/service-worker.js') return next();
      try {
        const worker = await compileWorker(publicFiles);
        response.setHeader('Content-Type', 'text/javascript; charset=utf-8');
        response.setHeader('Cache-Control', 'no-cache');
        response.end(worker);
      } catch (error) {
        next(error);
      }
    });
  },
};
