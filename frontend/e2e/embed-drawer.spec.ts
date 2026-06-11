import { test, expect } from '@playwright/test';
import { gotoPluginPage } from './helpers';

/** Embed action on a forms-table row opens the drawer with the shortcode. */
test('Embed button opens drawer showing the shortcode', async ({ page }) => {
  await gotoPluginPage(page);

  const row = page.locator('.ant-table-row', { hasText: 'Donation' });
  // The table renders only after the /forms proxy round-trips the live
  // backend; allow extra headroom over the default expect timeout.
  await expect(row).toBeVisible({ timeout: 30_000 });
  await row.getByRole('button', { name: 'Embed', exact: true }).click();

  const drawer = page.locator('.ant-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Donation').first()).toBeVisible();
  await expect(drawer.getByText(/\[paymentform id="[^"]+"\]/)).toBeVisible();
});
