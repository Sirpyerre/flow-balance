import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test('private shell marks the section, stacks the header and keeps content above the bottom bar', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta del shell');
  await page.getByLabel('Correo electrónico').fill(`shell-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Shell-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Shell-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();

  const navigation = page.getByRole('navigation', { name: 'Navegación privada' });
  for (const [path, section, heading] of [['/clients', 'Clientes', 'Clientes'], ['/projects', 'Proyectos', 'Proyectos'], ['/payments', 'Cobros', 'Cobros guardados']]) {
    await page.goto(path);
    await expect(page.getByRole('heading', { name: heading })).toBeVisible();
    const current = navigation.getByRole('link', { name: section });
    await expect(current).toHaveAttribute('aria-current', 'page');
    // The accent edge marks the section without relying on color alone.
    expect(await current.evaluate(link => getComputedStyle(link).boxShadow)).not.toBe('none');
    await expect(navigation.locator('[aria-current]')).toHaveCount(1);
    expect(await page.locator('.page-header').evaluate(header => getComputedStyle(header).flexDirection)).toBe('column');
  }

  // 360 px at 100% and the 180 px a 200% zoom leaves: no horizontal scroll, and the last element clears the bar.
  for (const width of [360, 180]) {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/clients');
    await expect(page.getByRole('heading', { name: 'Clientes' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth), `width ${width}`).toBeLessThanOrEqual(width);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const bar = (await navigation.boundingBox())!;
    const last = (await page.locator('main').evaluate(main => main.lastElementChild!.getBoundingClientRect().bottom));
    expect(last, `width ${width}`).toBeLessThanOrEqual(bar.y);
  }
});
