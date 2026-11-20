import { randomUUID } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { selectReportMonth } from './helpers/select-report-month';

test('converts USD, corrects currency and exports the selected month', async ({ page, baseURL }) => {
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de divisas');
  await page.getByLabel('Correo electrónico').fill(`currency-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Currency-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Currency-test-password-2026!');
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
    clientId: client.id, name: 'Sitio bilingüe', agreedAmount: '5000', agreedCurrency: 'MXN',
    agreedPaymentCount: 2, acquisitionSource: 'Directo',
  });
  await page.goto(`/payments/new?projectId=${project.id}`);
  await page.getByLabel('Fecha de pago').fill('2025-11-30');
  await page.getByRole('combobox', { name: 'Moneda', exact: true }).selectOption('USD');
  await page.getByLabel('Tipo de cambio (MXN por USD)').fill('17.25');
  await page.getByLabel('Importe (USD)').fill('100');
  await page.getByLabel('Comisión (USD)').fill('5');
  await page.getByLabel('Impuestos (USD)').fill('3');
  await expect(page.locator('output')).toContainText('MXN 1,725.00');
  await expect(page.locator('output')).toContainText('USD 92.00');
  await page.getByRole('button', { name: 'Guardar cobro' }).click();
  await page.getByRole('link', { name: 'Ver detalle del cobro' }).click();
  await expect(page.getByText('17.25 MXN por USD', { exact: true })).toBeVisible();
  const paymentPath = new URL(page.url()).pathname + new URL(page.url()).search;
  const paymentId = new URL(page.url()).searchParams.get('id');

  await page.getByRole('button', { name: 'Editar cobro' }).click();
  const correction = page.getByRole('form', { name: 'Editar cobro' });
  await correction.getByRole('combobox', { name: 'Moneda', exact: true }).selectOption('MXN');
  await correction.getByLabel('Confirmo los importes en MXN').check();
  await correction.getByLabel('Importe abonado (MXN)').fill('1500');
  await correction.getByLabel('Comisión de plataforma (MXN)').fill('100');
  await correction.getByLabel('Impuestos descontados (MXN)').fill('50');
  await correction.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(correction).toBeHidden();
  await expect(page.getByText('MXN 1,350.00', { exact: true })).toBeVisible();

  await write(`/api/payments/${paymentId}/charges`, {
    chargeType: 'tax', currency: 'MXN', amount: '75', paidOn: '2025-12-01',
  });
  await page.goto('/dashboard');
  await selectReportMonth(page, '2025-11');
  await expect(page.locator('#net-total')).toHaveText('MXN 1,350.00');
  await page.getByText('Ver movimientos del mes').click();
  await expect(page.getByRole('link', { name: /Cobro ·/ })).toHaveAttribute('href', paymentPath);
  await selectReportMonth(page, '2025-12');
  await expect(page.locator('#net-total')).toHaveText('MXN -75.00');
  await page.getByText('Ver movimientos del mes').click();
  await expect(page.getByRole('link', { name: /Impuesto separado/ })).toHaveAttribute('href', paymentPath);
  const downloading = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Descargar CSV' }).click();
  const download = await downloading;
  expect(download.suggestedFilename()).toBe('flow-balance-2025-12.csv');
  expect(await readFile((await download.path())!, 'utf8')).toContain(`2025-12,2025-12-01,"Impuesto pagado por separado",${paymentId},`);

  await page.goto(paymentPath);
  await page.getByRole('button', { name: 'Editar cobro' }).click();
  await correction.getByRole('combobox', { name: 'Moneda', exact: true }).selectOption('USD');
  await correction.getByLabel('Tipo de cambio (MXN por USD)').fill('18');
  await correction.getByLabel('Confirmo los importes en USD').check();
  await correction.getByLabel('Importe abonado (USD)').fill('100');
  await correction.getByLabel('Comisión de plataforma (USD)').fill('5');
  await correction.getByLabel('Impuestos descontados (USD)').fill('3');
  await correction.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(page.getByText('18 MXN por USD', { exact: true })).toBeVisible();
  await expect(page.getByText('MXN 1,656.00', { exact: true })).toBeVisible();

  await page.route(`**/api/projects/${project.id}`, async route => {
    const response = await route.fetch();
    const data = await response.json();
    await route.fulfill({ response, json: { ...data, isBalanceProvisional: true, unconvertedPaymentCount: 1 } });
  });
  await page.route('**/api/reports/project-balances', async route => {
    const response = await route.fetch();
    const data = await response.json();
    const projects = data.projects.map((row: object) => ({ ...row, isBalanceProvisional: true, unconvertedPaymentCount: 1 }));
    await route.fulfill({ response, json: { ...data, projects, totalOutstandingBalance: '0.00', excludedUnconvertedProjectCount: 1 } });
  });
  await page.goto(`/projects/view?id=${project.id}`);
  await expect(page.getByText('Saldo pendiente provisional', { exact: true })).toBeVisible();
  await expect(page.getByText(/Hay cobros sin convertir/)).toBeVisible();
  await page.goto('/dashboard');
  await expect(page.getByText('1 proyecto con cobros sin convertir excluido del saldo global.')).toBeVisible();
});
