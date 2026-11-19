import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { selectReportMonth } from './helpers/select-report-month';

test('matches dashboard figures, opens project capture and paginates history', async ({ page, baseURL }) => {
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de tablero');
  await page.getByLabel('Correo electrónico').fill(`dashboard-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Dashboard-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Dashboard-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('link', { name: 'Crear mi primer proyecto' })).toBeVisible();
  await page.getByRole('button', { name: 'Registrar cobro' }).click();
  await expect(page).toHaveURL(/projects\/new/);

  async function write(path: string, data: object) {
    const challenge = await page.request.get('/api/auth/csrf');
    const response = await page.request.post(path, { data, headers: {
      Origin: new URL(baseURL!).origin, 'X-CSRF-Token': (await challenge.json()).csrfToken, 'Idempotency-Key': randomUUID(),
    } });
    expect(response.status(), await response.text()).toBe(201);
    return response.json();
  }

  const client = await write('/api/clients', { name: 'Estudio Norte' });
  const agreement = { clientId: client.id, agreedCurrency: 'MXN', agreedPaymentCount: 4, acquisitionSource: 'Directo' };
  const pending = await write('/api/projects', { ...agreement, name: 'Integra', agreedAmount: '22000' });
  await page.goto('/dashboard');
  await page.getByRole('button', { name: 'Registrar cobro' }).click();
  await expect(page).toHaveURL(new RegExp(`projectId=${pending.id}$`));

  const settled = await write('/api/projects', { ...agreement, name: 'Acuerdo liquidado', agreedAmount: '6723' });
  for (const [projectId, clientPaidAmount, paidOn] of [
    [pending.id, '6600', '2025-10-07'],
    [settled.id, '4500', '2025-11-09'],
    [settled.id, '723', '2025-11-13'],
    [settled.id, '1500', '2025-11-05'],
  ]) {
    await write('/api/payments', { projectId, clientPaidAmount, paidOn, currency: 'MXN', sourceType: 'direct_client' });
  }
  await page.setViewportSize({ width: 1080, height: 800 });
  await page.goto('/dashboard');
  await selectReportMonth(page, '2025-11');
  await expect(page.locator('#total')).toHaveText('MXN 6,723.00');
  await expect(page.locator('#report-payments a')).toHaveCount(3);
  const chart = page.getByRole('group', { name: 'Elige un mes para consultar su reporte' });
  await expect(chart.getByRole('button', { name: 'Noviembre 2025, MXN 6,723.00' })).toHaveAttribute('aria-pressed', 'true');
  await chart.getByRole('button', { name: 'Octubre 2025, MXN 6,600.00' }).click();
  await expect(page.getByRole('button', { name: 'Mes del reporte: Octubre 2025' })).toBeVisible();
  await expect(page.locator('#total')).toHaveText('MXN 6,600.00');
  const search = page.getByRole('searchbox', { name: 'Buscar clientes o proyectos' });
  await search.fill('Integra');
  await expect(page.locator('#projects').getByRole('link', { name: 'Integra' })).toBeVisible();
  await expect(page.locator('#projects').getByRole('link', { name: 'Acuerdo liquidado' })).toBeHidden();
  await expect(page.locator('#clients-empty')).toHaveText('Sin coincidencias.');
  await search.clear();
  await expect(page.locator('#pending-total')).toHaveText('MXN 15,400.00');
  await expect(page.getByRole('progressbar', { name: 'Avance de cobro de Integra' })).toHaveJSProperty('value', 30);
  await page.getByLabel('Mostrar proyectos').selectOption('all');
  await selectReportMonth(page, '2025-12');
  await expect(page.locator('#total')).toHaveText('MXN 0.00');
  await expect(page.getByLabel('Mostrar proyectos')).toHaveValue('all');
  await expect(page.locator('#pending-total')).toHaveText('MXN 15,400.00');
  await page.getByRole('button', { name: 'Registrar cobro' }).click();
  const picker = page.getByRole('dialog', { name: 'Elige un proyecto' });
  await expect(picker.getByRole('link').first()).toContainText('Integra');
  await expect(picker.getByRole('link').nth(1)).toContainText('Liquidado');
  await picker.getByRole('button', { name: 'Cerrar', exact: true }).click();

  await page.setViewportSize({ width: 360, height: 760 });
  const periodButton = page.getByRole('button', { name: 'Mes del reporte: Diciembre 2025' });
  await periodButton.click();
  const monthPicker = page.getByRole('dialog', { name: 'Mes del reporte' });
  await expect(monthPicker.getByRole('button', { name: 'Diciembre 2025' })).toBeFocused();
  await monthPicker.getByRole('button', { name: 'Año anterior' }).click();
  await expect(monthPicker.getByRole('spinbutton', { name: 'Año' })).toHaveValue('2024');
  await expect(monthPicker.getByRole('group', { name: 'Meses' }).getByRole('button')).toHaveCount(12);
  await page.keyboard.press('Escape');
  await expect(periodButton).toBeFocused();
  await selectReportMonth(page, '2021-02');
  await expect(page.getByRole('button', { name: 'Mes del reporte: Febrero 2021' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  await page.route('**/api/payments', async route => {
    const response = await route.fetch({ url: route.request().url() + '?limit=2' });
    await route.fulfill({ response });
  }, { times: 1 });
  await page.getByRole('link', { name: 'Ver todos' }).click();
  await expect(page.locator('#history a')).toHaveCount(2);
  await page.getByRole('button', { name: 'Cargar más cobros' }).click();
  await expect(page.locator('#history a')).toHaveCount(4);
  await expect(page.getByRole('button', { name: 'Cargar más cobros' })).toBeHidden();
});
