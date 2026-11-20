import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test('edits project delivery independently of collection status', async ({ page, baseURL }) => {
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de entregas');
  await page.getByLabel('Correo electrónico').fill(`delivery-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Delivery-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Delivery-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();

  async function write(path: string, data: object) {
    const challenge = await page.request.get('/api/auth/csrf');
    const response = await page.request.post(path, { data, headers: {
      Origin: new URL(baseURL!).origin, 'X-CSRF-Token': (await challenge.json()).csrfToken, 'Idempotency-Key': randomUUID(),
    } });
    expect(response.status(), await response.text()).toBe(201);
    return response.json();
  }

  const client = await write('/api/clients', { name: 'Estudio Norte' });
  const project = await write('/api/projects', {
    clientId: client.id, name: 'Identidad visual', agreedAmount: '5000', agreedCurrency: 'MXN',
    agreedPaymentCount: 2, acquisitionSource: 'Directo',
  });
  await page.goto(`/projects/view?id=${project.id}`);
  const delivery = page.getByRole('region', { name: 'Estado de entrega' });
  await expect(delivery.getByRole('status')).toContainText('En curso');
  await delivery.getByLabel('Estado de entrega').selectOption('delivered');
  await page.route(`**/api/projects/${project.id}`, route => route.abort(), { times: 1 });
  await delivery.getByRole('button', { name: 'Guardar estado' }).click();
  await expect(delivery.getByRole('alert')).toBeVisible();
  await delivery.getByRole('button', { name: 'Guardar estado' }).click();
  await expect(delivery.getByRole('status')).toContainText('Entregado');
  await expect(page.getByText('Pendiente', { exact: true })).toBeVisible();
  await expect(page.locator('.metric-primary')).toContainText('MXN 5000.00');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);

  await write('/api/payments', { projectId: project.id, paidOn: '2025-11-15', currency: 'MXN', clientPaidAmount: '5000', sourceType: 'direct_client' });
  await page.reload();
  await expect(delivery.getByRole('status')).toContainText('Entregado');
  await delivery.getByLabel('Estado de entrega').selectOption('in_progress');
  await delivery.getByRole('button', { name: 'Guardar estado' }).click();
  await expect(delivery.getByRole('status')).toContainText('En curso');
  await expect(page.getByText('Liquidado', { exact: true })).toBeVisible();
});
