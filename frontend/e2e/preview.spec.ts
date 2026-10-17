import { test, expect, type Page } from '@playwright/test';
import { gotoPluginPage, mockWorkspace } from './helpers';

const EMBED_DRAWER_WIDTH_PX = 520;

/** Opens the embed drawer for the mocked Donation form. */
async function openEmbedDrawer(page: Page): Promise<void> {
  await mockWorkspace(page);
  await gotoPluginPage(page, '#/forms');
  const row = page.locator('.ant-table-row', { hasText: 'Donation' });
  await row.getByRole('button', { name: 'Embed', exact: true }).click();
}

test('preview tab widens the drawer for tablet and desktop layouts', async ({ page }) => {
  await openEmbedDrawer(page);
  const panel = page.locator('.ant-drawer-content-wrapper');

  await page.getByRole('tab', { name: 'Preview' }).click();

  await expect.poll(async () => (await panel.boundingBox())?.width ?? 0).toBeGreaterThan(EMBED_DRAWER_WIDTH_PX);
});

test('desktop preview renders the form at desktop width scaled to fit', async ({ page }) => {
  await openEmbedDrawer(page);
  await page.getByRole('tab', { name: 'Preview' }).click();
  await page.getByText('Desktop', { exact: true }).click();

  const frame = page.locator('.ant-drawer iframe');
  await expect(frame).toHaveCSS('width', '1280px');
  const box = await frame.boundingBox();
  const panel = await page.locator('.ant-drawer-content-wrapper').boundingBox();
  expect(box!.width).toBeLessThanOrEqual(panel!.width);
});
