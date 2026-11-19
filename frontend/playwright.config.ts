import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://flow-balance.test',
    channel: 'chrome',
    viewport: { width: 360, height: 760 },
  },
});
