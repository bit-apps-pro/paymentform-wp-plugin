import { test, expect } from '@playwright/test';
import { gotoPluginPage, isRestResponse } from './helpers';

/** Settings route renders its sections; health check fires a live GET /status. */
test('settings page renders and health check hits /status', async ({ page }) => {
  await gotoPluginPage(page, '#/settings');

  // Scope to the SPA root: wp-admin's own sidebar also has an "Appearance".
  const root = page.locator('#paymentform-admin-root');
  await expect(root.getByText('Appearance', { exact: true })).toBeVisible();
  await expect(root.getByText('Plugin info', { exact: true })).toBeVisible();

  const statusPromise = page.waitForResponse(
    (r) => isRestResponse(r, 'status') && r.request().method() === 'GET'
  );
  await page.getByRole('button', { name: 'Run health check' }).click();

  const statusRes = await statusPromise;
  expect(statusRes.status()).toBe(200);

  // Result rows render only after the whole check (including the /forms
  // probe, which round-trips the live backend) completes.
  await expect(root.getByText('/status', { exact: true })).toBeVisible({ timeout: 30_000 });
});
