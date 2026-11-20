import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';

// Opt-in walkthrough: CAPTURE_DIR=../docs/validation/screens/before npm run test:e2e -- tests/capture-screens.spec.ts
const captureDir = process.env.CAPTURE_DIR;
const viewports = { mobile: { width: 360, height: 800 }, desktop: { width: 1080, height: 900 } };
const themes = ['dark', 'light'] as const;

test.skip(!captureDir, 'Set CAPTURE_DIR to capture screens');
test.setTimeout(240_000);

test('captures key screens at 360 and 1080 px in both themes', async ({ page, baseURL }) => {
  mkdirSync(captureDir!, { recursive: true });

  async function write(path: string, data: object) {
    const challenge = await page.request.get('/api/auth/csrf');
    const response = await page.request.post(path, { data, headers: {
      Origin: new URL(baseURL!).origin, 'X-CSRF-Token': (await challenge.json()).csrfToken, 'Idempotency-Key': randomUUID(),
    } });
    expect(response.status(), await response.text()).toBe(201);
    return response.json();
  }

  async function shoot(name: string, path: string) {
    for (const [size, viewport] of Object.entries(viewports)) {
      await page.setViewportSize(viewport);
      for (const theme of themes) {
        await page.evaluate(value => localStorage.setItem('flow-balance-theme', value), theme);
        await page.goto(path);
        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: `${captureDir}/${name}-${size}-${theme}.png`, fullPage: true });
      }
    }
  }

  await page.goto('/');
  await shoot('landing', '/');
  await shoot('login', '/login');
  await shoot('register', '/register');
  await shoot('styleguide', '/styleguide'); // dev server only
  for (const [size, viewport] of Object.entries(viewports)) {
    await page.setViewportSize(viewport);
    for (const theme of themes) {
      await page.evaluate(value => localStorage.setItem('flow-balance-theme', value), theme);
      await page.goto('/styleguide');
      await page.getByRole('button', { name: 'Abrir diálogo' }).click();
      await page.screenshot({ path: `${captureDir}/styleguide-dialog-${size}-${theme}.png` });
    }
  }

  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de capturas');
  await page.getByLabel('Correo electrónico').fill(`capture-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Capture-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Capture-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();

  await shoot('dashboard-empty', '/dashboard');
  await shoot('payments-empty', '/payments');
  await shoot('projects-empty', '/projects');
  await shoot('clients-empty', '/clients');

  const client = await write('/api/clients', { name: 'Estudio Norte' });
  await write('/api/clients', { name: 'Cliente con un nombre extraordinariamente largo para probar el ajuste de texto' });
  const agreement = { clientId: client.id, agreedCurrency: 'MXN', agreedPaymentCount: 4, acquisitionSource: 'Directo' };
  const pending = await write('/api/projects', { ...agreement, name: 'Integra', agreedAmount: '22000' });
  const settled = await write('/api/projects', { ...agreement, name: 'Acuerdo liquidado', agreedAmount: '4500', agreedPaymentCount: 1 });
  let payment: { id: string } | undefined;
  const usd = await write('/api/payments', { projectId: pending.id, paidOn: '2026-11-10', currency: 'USD', exchangeRate: '17.25', clientPaidAmount: '100', platformFee: '5', withheldTaxAmount: '3', sourceType: 'platform', sourceName: 'Upwork' });
  for (const [projectId, paidOn, amount] of [
    [pending.id, '2026-09-05', '1500'], [pending.id, '2026-10-09', '4500'], [pending.id, '2026-11-13', '723'], [settled.id, '2026-11-05', '4500'],
  ]) {
    payment = await write('/api/payments', { projectId, paidOn, currency: 'MXN', clientPaidAmount: amount, sourceType: 'direct_client' });
  }

  await shoot('dashboard', '/dashboard');
  await shoot('payments', '/payments');
  await shoot('projects', '/projects');
  await shoot('clients', '/clients');
  await shoot('client-new', '/clients/new');
  await shoot('project-new', '/projects/new');
  await shoot('project-detail', `/projects/view?id=${pending.id}`);
  await shoot('project-edit', `/projects/edit?id=${pending.id}`);
  await shoot('payment-new', '/payments/new');
  await shoot('payment-detail', `/payments/view?id=${payment!.id}`);
  await shoot('payment-detail-usd', `/payments/view?id=${usd.id}`);
});
