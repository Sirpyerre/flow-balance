import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test('dashboard shows the right empty, error and priority states', async ({ page, baseURL }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de tablero');
  await page.getByLabel('Correo electrónico').fill(`board-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Board-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Board-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();

  // No data: the empty message is the only thing the payments panel says.
  await expect(page.getByText('Aún no tienes cobros.')).toBeVisible();

  async function write(path: string, data: object) {
    const challenge = await page.request.get('/api/auth/csrf');
    const response = await page.request.post(path, { data, headers: {
      Origin: new URL(baseURL!).origin, 'X-CSRF-Token': (await challenge.json()).csrfToken, 'Idempotency-Key': randomUUID(),
    } });
    expect(response.status(), await response.text()).toBe(201);
    return response.json();
  }
  const client = await write('/api/clients', { name: 'Estudio Norte' });
  const project = await write('/api/projects', { clientId: client.id, name: 'Integra', agreedAmount: '5000', agreedCurrency: 'MXN', agreedPaymentCount: 2, acquisitionSource: 'Directo' });
  await write('/api/payments', { projectId: project.id, paidOn: '2025-11-15', currency: 'MXN', clientPaidAmount: '1000', sourceType: 'direct_client' });

  await page.reload();
  await expect(page.getByRole('heading', { name: 'Últimos cobros' })).toBeVisible();
  await expect(page.getByText('Aún no tienes cobros.')).toBeHidden();

  // Priority at 360 px: the month total and its action come before the secondary indicators and the chart.
  const summary = await page.getByRole('region', { name: /Abonado/ }).boundingBox();
  const action = await page.getByRole('button', { name: 'Registrar cobro' }).boundingBox();
  const chart = await page.locator('#monthly-chart').boundingBox();
  expect(summary!.y).toBeLessThan(chart!.y);
  expect(action!.y).toBeLessThan(chart!.y);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  // The search placeholder is not clipped by the logout button.
  const search = (await page.getByRole('searchbox', { name: 'Buscar clientes o proyectos' }).boundingBox())!;
  expect(search.width).toBeGreaterThan(200);

  // A failed load is announced and can be retried.
  await page.route('**/api/dashboard', route => route.abort(), { times: 1 });
  await page.reload();
  await expect(page.getByRole('alert').filter({ hasText: 'No se pudo cargar el tablero.' })).toBeVisible();
  await page.getByRole('button', { name: 'Reintentar tablero' }).click();
  await expect(page.getByRole('heading', { name: 'Últimos cobros' })).toBeVisible();
});
