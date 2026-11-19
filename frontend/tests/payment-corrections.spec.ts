import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test('edits, retries and removes a payment from a mobile viewport', async ({ page, baseURL }) => {
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de correcciones');
  await page.getByLabel('Correo electrónico').fill(`correction-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Correction-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Correction-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('link', { name: 'Crear mi primer proyecto' })).toBeVisible();
  const origin = new URL(baseURL!).origin;

  async function write(path: string, data: object) {
    const challenge = await page.request.get('/api/auth/csrf');
    const response = await page.request.post(path, { data, headers: {
      Origin: origin, 'X-CSRF-Token': (await challenge.json()).csrfToken, 'Idempotency-Key': randomUUID(),
    } });
    expect(response.status(), await response.text()).toBe(201);
    return response.json();
  }

  const client = await write('/api/clients', { name: 'Estudio Norte' });
  const project = await write('/api/projects', {
    clientId: client.id, name: 'Identidad visual', agreedAmount: '5000', agreedCurrency: 'MXN',
    acquisitionSource: 'Directo', agreedPaymentCount: 1,
  });
  const payment = await write('/api/payments', {
    projectId: project.id, clientPaidAmount: '3000', currency: 'MXN', paidOn: '2025-11-30', sourceType: 'direct_client',
  });
  const path = `/api/payments/${payment.id}`;
  await write(`${path}/charges`, { paidOn: '2025-11-30', chargeType: 'commission', amount: '20', currency: 'MXN' });
  await page.goto(`/payments/view?id=${payment.id}`);
  const projectApi = `${origin}/api/projects/${project.id}`;
  await page.route(projectApi, route => route.abort(), { times: 1 });
  await page.getByRole('button', { name: 'Editar cobro' }).click();
  const form = page.getByRole('form', { name: 'Editar cobro' });
  await form.getByLabel('Importe abonado (MXN)').fill('2500');
  await expect(form.getByText('El conteo de pagos no está disponible.')).toBeVisible();
  await expect(form.getByRole('button', { name: 'Guardar cambios' })).toBeEnabled();
  await form.getByRole('button', { name: 'Reintentar consulta de pagos' }).click();
  await expect(form.getByText('Cantidad acordada de pagos alcanzada')).toBeVisible();
  await expect(form.getByText('Esta edición no cambia el número de pagos.')).toBeVisible();
  await expect(form.getByText('Al guardar este cobro:', { exact: false })).toHaveCount(0);
  await expect(form.getByLabel('Importe abonado (MXN)')).toHaveValue('2500');
  await form.getByLabel('Fecha de pago').fill('2025-12-01');
  await form.getByLabel('Comisión de plataforma (MXN)').fill('2501');
  await form.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(form.getByRole('alert')).toBeVisible();
  await expect(form.getByLabel('Fecha de pago')).toHaveValue('2025-12-01');
  await form.getByLabel('Comisión de plataforma (MXN)').fill('0');
  await page.route(`${origin}${path}`, async route => {
    expect(route.request().method()).toBe('PATCH');
    expect((await route.fetch()).status()).toBe(200);
    await route.abort('failed');
  }, { times: 1 });
  await form.getByRole('button', { name: 'Guardar cambios' }).click();
  await form.getByRole('button', { name: 'Reintentar guardado' }).click();
  await expect(page.getByText('MXN 2500.00', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('region', { name: 'Pagos del proyecto' }).getByText('1 de 1 pago recibido')).toBeVisible();

  let releaseSummary!: () => void;
  const holdSummary = new Promise<void>(resolve => { releaseSummary = resolve; });
  const summaryRequest = page.waitForRequest(projectApi);
  await page.route(projectApi, async route => {
    await holdSummary;
    await route.fulfill({ json: { paymentCount: 99, agreedPaymentCount: 1, outstandingBalance: '0.00' } }).catch(() => {});
  }, { times: 1 });
  await page.getByRole('button', { name: 'Editar cobro' }).click();
  await summaryRequest;
  await form.getByRole('button', { name: 'Cancelar' }).click();
  releaseSummary();
  await expect(page.getByRole('region', { name: 'Pagos del proyecto' })).toHaveCount(0);

  await page.getByRole('button', { name: 'Eliminar cobro' }).click();
  const dialog = page.getByRole('dialog', { name: 'Eliminar cobro' });
  await expect(dialog.getByText(/Comisión.*2025-11-30.*MXN 20.00/)).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancelar' }).click();
  await expect(dialog).toBeHidden();
  await write(`${path}/charges`, { paidOn: '2025-12-01', chargeType: 'tax', amount: '10', currency: 'MXN' });
  await page.getByRole('button', { name: 'Editar cobro' }).click();
  await form.getByLabel('Importe abonado (MXN)').fill('2499');
  await form.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(form.getByRole('alert')).toContainText('El cobro cambió');
  await expect(form.getByText('1 de 1 pago recibido')).toBeVisible();
  await expect(form.getByRole('button', { name: 'Revisar cobro vigente' })).toBeVisible();
  await form.getByRole('button', { name: 'Cancelar' }).click();
  await page.getByRole('button', { name: 'Eliminar cobro' }).click();
  await dialog.getByRole('button', { name: 'Confirmar eliminación' }).click();
  await expect(dialog.getByRole('alert')).toContainText('El cobro cambió');
  await dialog.getByRole('button', { name: 'Revisar cobro vigente' }).click();
  await page.getByRole('button', { name: 'Eliminar cobro' }).click();
  await expect(dialog.getByText(/Impuesto.*2025-12-01.*MXN 10.00/)).toBeVisible();
  await dialog.getByLabel('Motivo (opcional)').fill('Captura duplicada');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  await page.route(`${origin}${path}`, async route => {
    expect(route.request().method()).toBe('DELETE');
    expect((await route.fetch()).status()).toBe(204);
    await route.abort('failed');
  }, { times: 1 });
  await dialog.getByRole('button', { name: 'Confirmar eliminación' }).click();
  await expect(dialog.getByRole('alert')).toContainText('No se pudo confirmar');
  await dialog.getByRole('button', { name: 'Confirmar eliminación' }).click();
  await expect(page.getByRole('heading', { name: 'Identidad visual' })).toBeVisible();
  await expect(page.getByText('Aún no hay cobros.')).toBeVisible();
  await expect(page.getByText('MXN 5000.00', { exact: true })).toHaveCount(2);
});
