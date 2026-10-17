import { test, expect, type Locator, type Page } from '@playwright/test';
import { gotoPluginPage, mockWorkspace } from './helpers';

const VIEWPORTS = [
  { width: 1280, height: 800 },
  { width: 390, height: 844 },
];

/** Bottom edge of the fixed WP admin bar in viewport px. */
async function adminBarBottom(page: Page): Promise<number> {
  const box = await page.locator('#wpadminbar').boundingBox();
  return box ? box.y + box.height : 0;
}

/** Asserts the overlay's top edge sits at or below the admin bar. */
async function expectBelowAdminBar(page: Page, overlay: Locator): Promise<void> {
  await expect(overlay).toBeVisible();
  const barBottom = await adminBarBottom(page);
  // Polled because toasts and drawers slide in; the settled position is what users see.
  await expect.poll(async () => (await overlay.boundingBox())?.y ?? -1).toBeGreaterThanOrEqual(barBottom - 1);
}

for (const viewport of VIEWPORTS) {
  test.describe(`at ${viewport.width}px`, () => {
    test.use({ viewport });

    test('toast renders below the admin bar', async ({ page, context }) => {
      // wp-dev.io is plain http, where navigator.clipboard is undefined.
      await context.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: async () => undefined } });
      });
      await gotoPluginPage(page, '#/settings');
      await page.getByRole('button', { name: /Copy diagnostic info/ }).click();

      await expectBelowAdminBar(page, page.locator('.ant-message-notice').first());
    });

    test('embed drawer header renders below the admin bar', async ({ page }) => {
      await mockWorkspace(page);
      await gotoPluginPage(page, '#/forms');
      const row = page.locator('.ant-table-row', { hasText: 'Donation' });
      await expect(row).toBeVisible({ timeout: 30_000 });
      await row.getByRole('button', { name: 'Embed', exact: true }).click();

      await expectBelowAdminBar(page, page.locator('.ant-drawer-header'));
    });
  });
}

test('header tooltip renders below the admin bar', async ({ page }) => {
  await mockWorkspace(page);
  await gotoPluginPage(page, '#/forms');
  await page.getByRole('button', { name: /Switch to (dark|light) mode/ }).hover();

  await expectBelowAdminBar(page, page.locator('.ant-tooltip:visible'));
});
