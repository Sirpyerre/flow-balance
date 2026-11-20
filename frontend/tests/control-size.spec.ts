import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

// Touch-target floor from the ui-design-system spec: every visible button, select, text input and textarea is >= 44 px tall.
const selector = 'button, select, textarea, input:not([type=checkbox]):not([type=radio]):not([type=hidden]), a.button, a.btn';

test('visible controls are at least 44 px tall at 360 and 1080 px', async ({ page }) => {
  const all: string[] = [];
  async function offenders(label: string) {
    const found = await page.locator(selector).evaluateAll(elements => elements.flatMap(element => {
      const box = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      const hidden = style.visibility === 'hidden' || style.display === 'none' || box.width <= 1 || box.height <= 1 || element.closest('[hidden], dialog:not([open])');
      return hidden || box.height >= 43.5 ? [] : [`${element.tagName.toLowerCase()}${element.id ? '#' + element.id : ''}.${element.className} "${(element.textContent ?? '').trim().slice(0, 24)}" ${box.height.toFixed(1)}px`];
    }));
    all.push(...found.map(item => `${label}: ${item}`));
  }

  for (const viewport of [{ width: 360, height: 800 }, { width: 1080, height: 900 }]) {
    await page.setViewportSize(viewport);
    await page.goto('/');
    await offenders(`landing ${viewport.width}`);
    await page.goto('/register');
    await offenders(`register ${viewport.width}`);
  }

  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/register');
  await page.getByLabel('Nombre', { exact: true }).fill('Cuenta de tamaños');
  await page.getByLabel('Correo electrónico').fill(`sizes-${randomUUID()}@example.test`);
  await page.getByLabel('Contraseña', { exact: true }).fill('Sizes-test-password-2026!');
  await page.getByLabel('Confirma tu contraseña').fill('Sizes-test-password-2026!');
  await page.getByRole('button', { name: 'Crear cuenta' }).click();
  await expect(page.getByRole('heading', { name: 'Tablero' })).toBeVisible();

  for (const viewport of [{ width: 360, height: 800 }, { width: 1080, height: 900 }]) {
    await page.setViewportSize(viewport);
    await page.goto('/dashboard');
    await expect(page.getByRole('button', { name: 'Mes del reporte' })).toBeVisible();
    await offenders(`dashboard ${viewport.width}`);
    await page.getByRole('button', { name: /Mes del reporte/ }).click();
    await expect(page.getByRole('dialog', { name: 'Mes del reporte' })).toBeVisible();
    await offenders(`month dialog ${viewport.width}`);
    await page.keyboard.press('Escape');
    for (const path of ['/payments', '/clients', '/projects/new', '/clients/new']) {
      await page.goto(path);
      await expect(page.getByRole('navigation', { name: 'Navegación privada' })).toBeVisible();
      await offenders(`${path} ${viewport.width}`);
    }
  }
  expect(all).toEqual([]);
});
