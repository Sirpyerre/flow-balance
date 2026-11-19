import type { Page } from '@playwright/test';

export async function selectReportMonth(page: Page, period: string) {
  const [year] = period.split('-');
  const month = new Intl.DateTimeFormat('es-MX', { month: 'long', timeZone: 'UTC' })
    .format(new Date(period + '-01T00:00:00Z'));
  await page.getByRole('button', { name: /^Mes del reporte:/ }).click();
  const picker = page.getByRole('dialog', { name: 'Mes del reporte' });
  await picker.getByRole('spinbutton', { name: 'Año', exact: true }).fill(year);
  await picker.getByRole('button', { name: `${month[0].toUpperCase()}${month.slice(1)} ${year}`, exact: true }).click();
}
