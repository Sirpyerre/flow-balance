import { randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

async function register(page: Page) {
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de saldos');
  await page.getByLabel('Correo electrónico').fill(`balance-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Balance-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Balance-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();
}

async function write(request: APIRequestContext, origin: string, path: string, data: object) {
  const challenge = await request.get('/api/auth/csrf');
  expect(challenge.ok()).toBeTruthy();
  const response = await request.post(path, { data, headers: {
    Origin: origin, 'X-CSRF-Token': (await challenge.json()).csrfToken, 'Idempotency-Key': randomUUID(),
  } });
  expect(response.status(), await response.text()).toBe(201);
  return response.json();
}

test('shows current balances, traces payments and keeps the monthly report independent', async ({ page, context, baseURL }) => {
  const origin = new URL(baseURL!).origin;
  await register(page);
  const balances = page.getByRole('region', { name: 'Saldos vigentes por proyecto' });
  await expect(balances.getByText('Aún no tienes proyectos')).toBeVisible();
  const client = await write(page.request, origin, '/api/clients', { name: 'Estudio Norte' });
  const project = await write(page.request, origin, '/api/projects', {
    clientId: client.id, name: 'Identidad visual', acquisitionSource: 'Directo', agreedPaymentCount: 3,
    agreedAmount: '5000', agreedCurrency: 'MXN',
  });
  const payment = { projectId: project.id, currency: 'MXN', sourceType: 'direct_client' };
  await write(page.request, origin, '/api/payments', { ...payment, clientPaidAmount: '3000', paidOn: '2025-11-30', platformFee: '200' });
  await write(page.request, origin, '/api/payments', { ...payment, clientPaidAmount: '800', paidOn: '2025-12-01' });
  await page.reload();
  const filter = balances.getByLabel('Mostrar proyectos');
  await expect(filter).toHaveValue('outstanding');
  await expect(balances.getByText('MXN 1200.00', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  for (const [month, total] of [['2025-11', '3000.00'], ['2025-12', '800.00']]) {
    await page.getByLabel('Mes del reporte').fill(month);
    await expect(page.locator('#report-total')).toHaveText(`MXN ${total}`);
    await expect(balances.getByText('MXN 1200.00', { exact: true })).toBeVisible();
  }
  await expect(balances.getByRole('link', { name: 'Identidad visual' })).toBeVisible();
  await balances.getByRole('link', { name: 'Identidad visual' }).click();
  await expect(page.getByText('MXN 1200.00', { exact: true })).toBeVisible();
  await page.getByRole('link', { name: /2025-11-30/ }).click();
  await expect(page.getByRole('heading', { name: 'Detalle del cobro' })).toBeVisible();
  await write(page.request, origin, '/api/payments', { ...payment, clientPaidAmount: '1200', paidOn: '2025-12-02' });
  await page.goto('/dashboard');
  await expect(balances.getByText('No tienes proyectos con saldo pendiente')).toBeVisible();
  await filter.selectOption('all');
  await expect(balances.getByText(/Liquidado/)).toBeVisible();
  await write(page.request, origin, '/api/payments', {
    ...payment, clientPaidAmount: '300', paidOn: '2025-12-03', excessReason: 'Trabajo adicional',
  });
  await page.reload();
  await filter.selectOption('all');
  await expect(balances.getByText('MXN 300.00', { exact: true })).toBeVisible();
  await balances.getByRole('link', { name: 'Identidad visual' }).click();
  await expect(page.getByText('Con excedente', { exact: true })).toBeVisible();
  await expect(page.getByText('MXN 300.00', { exact: true }).first()).toBeVisible();
  await page.goto('/dashboard');
  await expect(filter).toBeVisible();
  await context.setOffline(true);
  await expect(balances).toBeHidden();
  await page.reload();
  await expect(balances).toBeHidden();
  await expect(page.locator('#project-balances').getByRole('listitem', { includeHidden: true })).toHaveCount(0);
});

test('retries a failed balance request and discards a late response after cleanup', async ({ page }) => {
  await register(page);
  const balances = page.getByRole('region', { name: 'Saldos vigentes por proyecto' });
  await page.route('**/api/reports/project-balances', route => route.fulfill({ status: 503, body: '{}' }), { times: 1 });
  await page.reload();
  await expect(balances.getByText('No se pudieron cargar los saldos.')).toBeVisible();
  await expect(page.locator('#report-total')).toHaveText('MXN 0.00');
  await balances.getByRole('button', { name: 'Reintentar' }).click();
  await expect(balances.getByText('Aún no tienes proyectos')).toBeVisible();
  await page.route('**/api/reports/project-balances', route => route.fulfill({ status: 503, body: '{}' }), { times: 1 });
  await page.reload();
  await expect(balances.getByText('No se pudieron cargar los saldos.')).toBeVisible();

  await page.evaluate(() => {
    const original = window.fetch;
    window.fetch = (input, options) => {
      if (input !== '/api/reports/project-balances') return original(input, options);
      return new Promise(resolve => {
        window.addEventListener('release-late-balances', () => resolve(new Response(JSON.stringify({ projects: [{
          projectId: '42', name: 'Proyecto obsoleto', agreedAmount: '15.00', clientPaidTotal: '0.00',
          outstandingBalance: '15.00', excessAmount: '0.00', currency: 'MXN', collectionStatus: 'outstanding',
        }] }))), { once: true });
      });
    };
  });
  await balances.getByRole('button', { name: 'Reintentar' }).click();
  await expect(balances.getByText('Cargando saldos…')).toBeVisible();
  await expect(balances.getByText('Aún no tienes proyectos')).toHaveCount(0);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await expect(balances).toBeHidden();
  await page.evaluate(async () => {
    window.dispatchEvent(new Event('release-late-balances'));
    await new Promise(resolve => setTimeout(resolve, 0));
  });
  await expect(page.locator('#project-balances')).toBeEmpty();
  await page.reload();
  await expect(balances.getByText('Aún no tienes proyectos')).toBeVisible();
});

test('labels a historical project without displaying an invented balance', async ({ page }) => {
  await register(page);
  await page.route('**/api/projects/42', route => route.fulfill({
    json: { id: '42', name: 'Proyecto histórico', client: { id: '1', name: 'Estudio Norte' },
      agreedAmount: null, agreedPaymentCount: null, acquisitionSource: null, paymentCount: 0,
      clientPaidTotal: '0.00', payments: [], outstandingBalance: null, excessAmount: null,
      collectionStatus: 'undetermined' },
  }));
  await page.goto('/projects/view?id=42');
  await expect(page.getByText('Saldo por determinar')).toBeVisible();
  await expect(page.getByText('Saldo pendiente', { exact: true })).toHaveCount(0);
  await expect(page.getByText('MXN 0.00', { exact: true })).toBeVisible();
});
