import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test('rejected fields are marked, explained and focused in project, payment and charge forms', async ({ page, baseURL }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de errores');
  await page.getByLabel('Correo electrónico').fill(`errors-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Errors-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Errors-test-password-2026!');
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
  const project = await write('/api/projects', { clientId: client.id, name: 'Integra', agreedAmount: '5000', agreedCurrency: 'MXN', agreedPaymentCount: 2, acquisitionSource: 'Directo' });
  const payment = await write('/api/payments', { projectId: project.id, paidOn: '2026-11-05', currency: 'MXN', clientPaidAmount: '1000', sourceType: 'direct_client' });

  // New project with an unusable amount.
  await page.goto('/projects/new');
  await page.getByRole('combobox', { name: 'Cliente' }).selectOption({ label: 'Estudio Norte' });
  await page.getByLabel('Nombre del proyecto').fill('Con error');
  await page.getByLabel('Origen del proyecto').fill('Directo');
  await page.getByLabel('Monto acordado (MXN)').fill('abc');
  await page.getByLabel('Pagos acordados').fill('2');
  await page.getByRole('button', { name: 'Guardar proyecto' }).click();
  const amount = page.getByLabel('Monto acordado (MXN)');
  await expect(amount).toHaveAttribute('aria-invalid', 'true');
  await expect(amount).toBeFocused();
  await expect(page.locator('#agreedAmount-error')).toBeVisible();

  // Payment with an unusable amount.
  await page.goto(`/payments/new?${new URLSearchParams({ projectId: project.id })}`);
  await page.getByLabel(/^Importe \(MXN\)/).fill('abc');
  await page.getByRole('button', { name: 'Guardar cobro' }).click();
  const paid = page.getByLabel(/^Importe \(MXN\)/);
  await expect(paid).toHaveAttribute('aria-invalid', 'true');
  await expect(paid).toBeFocused();
  await expect(page.locator('#clientPaidAmount-error')).toBeVisible();

  // Separate charge with an unusable amount.
  await page.goto(`/payments/view?${new URLSearchParams({ id: payment.id })}`);
  await page.getByText('Registrar cargo separado').click();
  await page.getByLabel('Importe del cargo (MXN)').fill('abc');
  await page.getByRole('button', { name: 'Guardar cargo' }).click();
  await expect(page.getByLabel('Importe del cargo (MXN)')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByLabel('Importe del cargo (MXN)')).toBeFocused();
});
