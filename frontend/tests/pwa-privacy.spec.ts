import { expect, test } from '@playwright/test';

test('the PWA controls pages and shows an empty offline response for private navigation', async ({ page, context }) => {
  await page.goto('/');
  const worker = await page.evaluate(async () => {
    const response = await fetch('/service-worker.js', { redirect: 'error' });
    return { status: response.status, type: response.headers.get('content-type') };
  });
  expect(worker.status).toBe(200);
  expect(worker.type).toMatch(/javascript/);
  await expect(page.locator('link[rel=manifest]')).toHaveAttribute('href', '/manifest.webmanifest');
  await expect.poll(() => page.evaluate(() => navigator.serviceWorker.controller?.scriptURL)).toMatch(/\/service-worker\.js$/);
  await context.setOffline(true);
  await page.goto('/dashboard');
  await expect(page.getByRole('heading', { name: 'Sin conexión' })).toBeVisible();
  await expect(page.getByText('Cobros guardados')).toHaveCount(0);
  expect(await page.evaluate(async () => {
    const entries = (await Promise.all((await caches.keys()).map(async key => (await caches.open(key)).keys()))).flat();
    return entries.filter(entry => ['/api', '/dashboard', '/projects/new'].some(path => new URL(entry.url).pathname.startsWith(path))).length;
  })).toBe(0);
});
