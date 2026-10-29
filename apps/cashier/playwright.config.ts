import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  use: { baseURL: 'http://127.0.0.1:5178', viewport: { width: 1440, height: 960 } },
  webServer: { command: 'npm run dev:web', url: 'http://127.0.0.1:5178', reuseExistingServer: !process.env.CI },
})
