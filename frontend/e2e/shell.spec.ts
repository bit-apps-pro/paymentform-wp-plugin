import { test, expect } from '@playwright/test';
import { gotoPluginPage, mockWorkspace } from './helpers';

const HEADER_HEIGHT_PX = 64;

test('app header keeps its designed height and background', async ({ page }) => {
  await mockWorkspace(page);
  await gotoPluginPage(page, '#/forms');
  const header = page.locator('#paymentform-admin-root header').first();

  await expect.poll(async () => Math.round((await header.boundingBox())?.height ?? 0)).toBe(HEADER_HEIGHT_PX);
  expect(await header.evaluate((el) => getComputedStyle(el).backgroundColor)).not.toBe('rgba(0, 0, 0, 0)');
});

test.describe('at 390px', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('shell starts below the admin bar', async ({ page }) => {
    await gotoPluginPage(page, '#/forms');
    const bar = await page.locator('#wpadminbar').boundingBox();
    const root = await page.locator('#paymentform-admin-root').boundingBox();

    expect(root!.y).toBeGreaterThanOrEqual(bar!.y + bar!.height - 1);
  });

  test('header actions stay on screen', async ({ page }) => {
    await mockWorkspace(page);
    await gotoPluginPage(page, '#/forms');

    await expect(page.getByRole('button', { name: /Switch to (dark|light) mode/ })).toBeInViewport({ ratio: 1 });
  });

  test('preview drawer uses the full phone width', async ({ page }) => {
    await mockWorkspace(page);
    await gotoPluginPage(page, '#/forms');
    await page.locator('.ant-table-row', { hasText: 'Donation' }).getByRole('button', { name: 'Embed', exact: true }).click();
    await page.getByRole('tab', { name: 'Preview' }).click();

    const panel = page.locator('.ant-drawer-content-wrapper');
    await expect.poll(async () => Math.round((await panel.boundingBox())?.width ?? 0)).toBe(390);
  });
});

for (const width of [1280, 900]) {
  test.describe(`with the admin menu at ${width}px`, () => {
    test.use({ viewport: { width, height: 800 } });

    test('content starts where the admin menu ends', async ({ page }) => {
      await gotoPluginPage(page, '#/forms');
      const menu = await page.locator('#adminmenuwrap').boundingBox();
      const root = await page.locator('#paymentform-admin-root').boundingBox();

      expect(Math.abs(root!.x - (menu!.x + menu!.width))).toBeLessThanOrEqual(1);
    });
  });
}
