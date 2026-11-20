import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test('private lists show empty and recoverable error states', async ({ page }) => {
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de estados');
  await page.getByLabel('Correo electrónico').fill(`states-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('States-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('States-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();

  await page.goto('/clients');
  const state = page.locator('.state');
  await expect(state).toHaveText('Aún no tienes clientes.');
  await expect(state).toHaveClass(/state-empty/);
  await expect(page.locator('.page-header-subtitle')).toHaveText('0 clientes');

  await page.route('**/api/dashboard', route => route.abort(), { times: 1 });
  await page.goto('/projects');
  await expect(page.getByRole('alert')).toHaveText('No se pudo cargar el listado.');
  await expect(page.locator('.state')).toHaveClass(/state-error/);
  await page.getByRole('button', { name: 'Reintentar' }).click();
  await expect(page.locator('.state')).toHaveText('Aún no tienes proyectos.');
});

test('lists keep long names and large amounts inside 360 px with actions visible', async ({ page, baseURL }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de nombres largos');
  await page.getByLabel('Correo electrónico').fill(`long-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Long-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Long-test-password-2026!');
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
  const longName = 'Estudio de diseño y comunicación integral Supercalifragilístico-Espialidoso Internacional';
  const client = await write('/api/clients', { name: longName });
  const project = await write('/api/projects', { clientId: client.id, name: `Proyecto ${longName}`, agreedAmount: '123456789.99', agreedCurrency: 'MXN', agreedPaymentCount: 3, acquisitionSource: 'Directo' });
  await write('/api/payments', { projectId: project.id, paidOn: '2026-11-05', currency: 'MXN', clientPaidAmount: '98765432.10', sourceType: 'direct_client' });

  for (const [path, action] of [['/clients', 'Nuevo cliente'], ['/projects', 'Nuevo proyecto'], ['/payments', 'Registrar cobro'], ['/dashboard', 'Registrar cobro']]) {
    await page.goto(path);
    await expect(page.getByRole(action === 'Registrar cobro' ? 'button' : 'link', { name: action }).or(page.getByRole('link', { name: action })).first()).toBeVisible();
    await expect(page.locator('.state-loading')).toHaveCount(0);
    if (path === '/clients') await expect(page.locator('.page-header-subtitle')).toHaveText('1 cliente');
    if (path === '/projects') await expect(page.locator('.page-header-subtitle')).toHaveText('1 proyecto');
    expect(await page.evaluate(() => document.documentElement.scrollWidth), path).toBeLessThanOrEqual(360);
  }
});
