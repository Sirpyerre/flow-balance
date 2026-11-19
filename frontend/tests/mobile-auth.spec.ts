import { expect, test } from '@playwright/test';

test('mobile forms have labels, autocomplete and room at 360 px', async ({ page }) => {
  await page.goto('/register');
  await expect(page.getByLabel('Nombre', { exact: true })).toHaveAttribute('autocomplete', 'name');
  await expect(page.getByLabel('Correo electrónico')).toHaveAttribute('autocomplete', 'email');
  await expect(page.getByLabel('Contraseña', { exact: true })).toHaveAttribute('autocomplete', 'new-password');
  await expect(page.getByLabel('Confirma tu contraseña')).toHaveAttribute('autocomplete', 'new-password');
  const confirmation = page.getByLabel('Confirma tu contraseña');
  await confirmation.fill('example-password');
  await page.getByRole('button', { name: 'Mostrar confirmación de contraseña' }).click();
  await expect(confirmation).toHaveAttribute('type', 'text');
  await expect(page.getByLabel('Contraseña', { exact: true })).toHaveAttribute('type', 'password');
  await page.getByRole('button', { name: 'Ocultar confirmación de contraseña' }).click();
  await expect(confirmation).toHaveAttribute('type', 'password');
  await expect(confirmation).toHaveValue('example-password');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  await page.getByRole('link', { name: 'Iniciar sesión' }).last().click();
  await expect(page.getByLabel('Correo electrónico')).toHaveAttribute('autocomplete', 'username');
  await expect(page.getByLabel('Contraseña', { exact: true })).toHaveAttribute('autocomplete', 'current-password');
  const password = page.getByLabel('Contraseña', { exact: true });
  await password.fill('example-password');
  await page.getByRole('button', { name: 'Mostrar contraseña' }).press('Space');
  await expect(password).toHaveAttribute('type', 'text');
  await page.getByRole('button', { name: 'Ocultar contraseña' }).click();
  await expect(password).toHaveAttribute('type', 'password');
  await expect(password).toHaveValue('example-password');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});
