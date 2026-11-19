import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { selectReportMonth } from './helpers/select-report-month';

test('registers a project, records income and charges, and opens the monthly report on mobile', async ({ page, context, baseURL }) => {
  const origin = new URL(baseURL ?? 'https://flow-balance.test').origin;
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de prueba de ingresos');
  await page.getByLabel('Correo electrónico').fill(`income-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Income-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Income-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await page.getByRole('link', { name: 'Crear mi primer proyecto' }).click();
  await page.getByRole('link', { name: 'Crear cliente' }).click();
  await page.getByLabel('Nombre del cliente').fill('Estudio Norte');
  await page.getByRole('button', { name: 'Guardar cliente' }).click();
  await expect(page.getByRole('combobox', { name: 'Cliente' }).locator('option:checked')).toHaveText('Estudio Norte');
  await page.getByLabel('Nombre del proyecto').fill('Identidad visual');
  await page.getByLabel('Origen del proyecto').fill('Recomendación');
  await page.getByLabel('Monto acordado (MXN)').fill('5000');
  await page.getByLabel('Pagos acordados').fill('3');
  await page.getByRole('button', { name: 'Guardar proyecto' }).click();
  await expect(page.getByText('0 de 3 pagos recibidos')).toBeVisible();
  await expect(page.getByText('Recomendación', { exact: true })).toBeVisible();
  const projectUrl = page.url();
  await page.goto('/projects/new');
  await page.getByRole('combobox', { name: 'Cliente' }).selectOption({ label: 'Estudio Norte' });
  await page.getByLabel('Nombre del proyecto').fill('Segundo proyecto');
  await page.getByLabel('Origen del proyecto').fill('Contacto directo');
  await page.getByLabel('Monto acordado (MXN)').fill('1000');
  await page.getByLabel('Pagos acordados').fill('1');
  await page.getByRole('button', { name: 'Guardar proyecto' }).click();
  await expect(page.getByRole('heading', { name: 'Segundo proyecto' })).toBeVisible();
  await expect(page.getByText('Estudio Norte', { exact: true })).toBeVisible();
  await page.goto(projectUrl);
  await page.getByRole('link', { name: 'Registrar cobro' }).click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  const paymentSummary = page.getByRole('region', { name: 'Pagos del proyecto' });
  await expect(paymentSummary.getByText('0 / 3', { exact: true })).toBeVisible();
  await expect(paymentSummary.getByText('Tras guardar: 1 / 3 pagos')).toBeVisible();
  await expect(paymentSummary.getByText('MXN 5,000.00', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Guardar cobro' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(paymentSummary.getByText('0 / 3', { exact: true })).toBeVisible();
  await page.getByLabel('Fecha de pago').fill('2025-11-30');
  await page.getByLabel('Importe (MXN)').fill('3000');
  await page.getByLabel('Comisión').fill('150.25');
  await page.getByLabel('Impuestos').fill('49.75');
  await expect(page.getByText('Dinero recibido al cobrar: MXN 2800.00')).toBeVisible();

  let savedId: string | undefined;
  const keys: string[] = [];
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/api/payments' && request.method() === 'POST') {
      keys.push(request.headers()['idempotency-key']);
    }
  });
  await page.route(`${origin}/api/payments`, async route => {
    const response = await route.fetch();
    expect(response.status()).toBe(201);
    savedId = (await response.json()).id;
    await route.abort('failed');
  }, { times: 1 });
  await page.getByRole('button', { name: 'Guardar cobro' }).evaluate((button: HTMLButtonElement) => {
    button.form!.requestSubmit();
    button.form!.requestSubmit();
  });
  await expect(page.getByRole('alert')).toContainText('Reintenta el mismo guardado');
  expect(keys).toHaveLength(1);
  await expect(paymentSummary.getByText('1 / 3', { exact: true })).toBeVisible();
  const replay = page.waitForResponse(response => new URL(response.url()).pathname === '/api/payments');
  await page.getByRole('button', { name: 'Guardar cobro' }).click();
  expect((await replay).status()).toBe(200);
  expect(keys[1]).toBe(keys[0]);
  await expect(paymentSummary.getByText('1 de 3 pagos recibidos', { exact: true })).toBeVisible();
  await expect(paymentSummary.getByText('Tras guardar:', { exact: false })).toHaveCount(0);
  await page.getByRole('link', { name: 'Ver detalle del cobro' }).click();
  await expect(page).toHaveURL(new RegExp(`id=${savedId}$`));
  await expect(page.getByRole('heading', { name: 'Detalle del cobro' })).toBeVisible();
  await page.getByText('Registrar cargo separado').click();
  await page.getByLabel('Fecha del cargo').fill('2025-12-02');
  await page.getByLabel('Importe del cargo (MXN)').fill('50');
  await page.route(`${origin}/api/payments/${savedId}/charges`, async route => {
    expect((await route.fetch()).status()).toBe(201);
    await route.abort('failed');
  }, { times: 1 });
  await page.getByRole('button', { name: 'Guardar cargo' }).click();
  await expect(page.getByRole('alert')).toContainText('reintenta');
  const chargeReplay = page.waitForResponse(response => response.url().endsWith('/charges'));
  await page.getByRole('button', { name: 'Guardar cargo' }).click();
  expect((await chargeReplay).status()).toBe(200);
  await expect(page.getByText('Comisión · 2025-12-02 · MXN 50.00')).toBeVisible();
  await expect(page.getByText('Comisión · 2025-12-02 · MXN 50.00')).toHaveCount(1);
  await page.getByText('Registrar cargo separado').click();
  await page.getByLabel('Tipo de cargo').selectOption('tax');
  await page.getByLabel('Fecha del cargo').fill('2025-12-03');
  await page.getByLabel('Importe del cargo (MXN)').fill('25');
  await page.getByRole('button', { name: 'Guardar cargo' }).click();
  await expect(page.getByText('Impuesto · 2025-12-03 · MXN 25.00')).toBeVisible();
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.getByRole('link', { name: 'Volver al tablero' }).click();
  await selectReportMonth(page, '2025-11');
  await expect(page.locator('#report-total')).toHaveText('MXN 3,000.00');
  await page.locator('#report-payments').getByRole('link').click();
  await expect(page.getByText('Comisión · 2025-12-02 · MXN 50.00')).toBeVisible();
  await context.setOffline(true);
  await expect(page.getByText('Comisión · 2025-12-02 · MXN 50.00')).toBeHidden();
  await page.reload();
  await expect(page.getByText('Comisión · 2025-12-02 · MXN 50.00')).toHaveCount(0);
  await context.setOffline(false);
  await page.goto(projectUrl);
  await page.getByRole('link', { name: 'Registrar cobro' }).click();
  await page.getByLabel('Fecha de pago').fill('2025-12-01');
  await page.getByLabel('Fuente del cobro').selectOption('platform');
  await page.getByLabel('Nombre de la fuente').fill('Upwork');
  await page.getByLabel('Importe (MXN)').fill('2000.001');
  await page.getByRole('button', { name: 'Guardar cobro' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByLabel('Nombre de la fuente')).toHaveValue('Upwork');
  await page.getByLabel('Importe (MXN)').fill('2100');
  await expect(page.getByText('El total supera el monto acordado en MXN 100.00.')).toBeVisible();
  await page.getByLabel('Razón del excedente').fill('Trabajo adicional');
  await page.getByRole('button', { name: 'Guardar cobro' }).click();
  await expect(page.getByText('Excedente al guardar: MXN 100.00.')).toBeVisible();
  await page.getByRole('link', { name: 'Ver detalle del cobro' }).click();
  await expect(page.getByText('Trabajo adicional')).toBeVisible();
  await page.goto(projectUrl);
  await expect(page.getByText('2 de 3 pagos recibidos')).toBeVisible();
  expect(keys[2]).not.toBe(keys[0]);
  expect(keys[3]).toBe(keys[2]);
});

test('shows agreed, additional, and historical payment counts on desktop', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de resumen de pagos');
  await page.getByLabel('Correo electrónico').fill(`payment-summary-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Income-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Income-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();
  let count = 2;
  let agreed: number | null = 4;
  await page.route('**/api/projects/123', route => route.fulfill({ json: {
    id: '123', name: 'Proyecto de pruebas', client: { name: 'Cliente de pruebas' },
    paymentCount: count, agreedPaymentCount: agreed, outstandingBalance: '1500.00',
  } }));

  await page.goto('/payments/new?projectId=123');
  const summary = page.getByRole('region', { name: 'Pagos del proyecto' });
  await expect(summary.getByText('2 / 4', { exact: true })).toBeVisible();
  await expect(summary.locator('.payment-summary-track > span')).toHaveCount(4);
  await expect(summary.locator('.payment-summary-track > .is-filled')).toHaveCount(2);
  await expect(summary.getByText('Tras guardar: 3 / 4 pagos')).toBeVisible();
  await expect(summary.getByText('MXN 1,500.00')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(1280);

  count = 4;
  await page.reload();
  await expect(summary.getByText('Cantidad acordada de pagos alcanzada')).toBeVisible();
  await expect(summary.getByText('Tras guardar: 5 / 4 pagos')).toBeVisible();
  count = 5;
  await page.reload();
  await expect(summary.getByText('1 pago adicional')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Cancelar', exact: true })).toHaveAttribute('href', '/projects/view?id=123');

  count = 2;
  agreed = null;
  await page.reload();
  await expect(summary.getByText('2', { exact: true })).toBeVisible();
  await expect(summary.getByText('Cantidad acordada sin registrar')).toBeVisible();
  await expect(summary.getByText('Tras guardar: 3 pagos')).toBeVisible();
  await expect(summary.getByText(/Falta/)).toHaveCount(0);

  await page.route('**/api/payments/234', route => route.fulfill({ json: {
    id: '234', version: '1', project: { id: '123', name: 'Proyecto de pruebas' },
    client: { name: 'Cliente de pruebas' }, paidOn: '2025-11-30',
    clientPaidAmount: '100.00', platformFee: '0.00', withheldTaxAmount: '0.00', receivedAmount: '100.00',
    sourceType: 'direct_client', charges: [],
  } }));
  await page.goto('/payments/view?id=234');
  await page.getByRole('button', { name: 'Editar cobro' }).click();
  await expect(summary.getByText('2 pagos recibidos', { exact: true })).toBeVisible();
  await expect(summary.getByText('Esta edición no cambia el número de pagos.')).toBeVisible();
  await expect(summary.getByText('Tras guardar:', { exact: false })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(1280);
});
