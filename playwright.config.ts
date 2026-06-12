import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

const dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * E2E suite against a live dev WordPress install (default http://wp-dev.io).
 * See frontend/e2e/README.md for setup, env vars and the non-destructive policy.
 */
export default defineConfig({
  testDir: 'frontend/e2e',
  reporter: 'list',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  // Single worker: the specs share one live WP install whose /forms proxy
  // hits the real backend; parallel load makes it flaky-slow.
  workers: 1,
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'http://wp-dev.io',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      // Logs into wp-login.php once and persists cookies for every spec.
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: path.join(dirname, 'frontend/e2e/.auth/state.json'),
      },
      dependencies: ['setup'],
    },
  ],
});
