import { test, expect, type Locator, type Page } from '@playwright/test';
import { gotoPluginPage } from './helpers';

/** Locates the first live Donation row, waiting out the /forms proxy round-trip to the backend. */
async function donationRow(page: Page): Promise<Locator> {
  await gotoPluginPage(page);
  // The shared dev workspace may hold several forms named Donation; any one will do.
  const row = page.locator('.ant-table-row', { hasText: 'Donation' }).first();
  await expect(row).toBeVisible({ timeout: 30_000 });
  return row;
}

test('Copy shortcode copies in one click and confirms with a toast', async ({ page }) => {
  const row = await donationRow(page);

  await row.getByRole('button', { name: /Copy shortcode/ }).click();

  await expect(page.locator('.ant-message-notice', { hasText: 'Shortcode copied' })).toBeVisible();
});

test('embed menu offers shortcode, block markup and iframe URL', async ({ page }) => {
  const row = await donationRow(page);

  await row.getByRole('button', { name: 'More embed options' }).click();

  const menu = page.locator('.ant-dropdown-menu');
  for (const label of ['Shortcode', 'Block markup', 'Iframe URL']) {
    await expect(menu.getByRole('menuitem', { name: new RegExp(`^${label}`) })).toBeVisible();
  }
});
