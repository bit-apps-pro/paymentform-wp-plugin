import { test, expect, type Page } from '@playwright/test';
import { gotoPluginPage, mockWorkspace, MOCK_FORM } from './helpers';

const DESKTOP_WIDTH_PX = 1280;

/** Opens the preview page for the mocked Donation form from its table row. */
async function openPreview(page: Page): Promise<void> {
  await mockWorkspace(page);
  await gotoPluginPage(page, '#/forms');
  const row = page.locator('.ant-table-row', { hasText: 'Donation' });
  await row.getByRole('button', { name: 'Preview', exact: true }).click();
}

test('Preview button opens the form preview page', async ({ page }) => {
  await openPreview(page);

  await expect(page).toHaveURL(new RegExp(`#/forms/${MOCK_FORM.id}/preview$`));
  await expect(page.locator('.ant-breadcrumb')).toContainText('Donation');
});

test('desktop preview renders the form at desktop width scaled to fit', async ({ page }) => {
  await openPreview(page);
  await page.getByText('Desktop', { exact: true }).click();

  const frame = page.locator('#paymentform-admin-root main iframe');
  await expect(frame).toHaveCSS('width', `${DESKTOP_WIDTH_PX}px`);
  const content = await page.locator('#paymentform-admin-root main').boundingBox();
  await expect.poll(async () => (await frame.boundingBox())?.width ?? Infinity).toBeLessThanOrEqual(content!.width);
});

test('Back to forms returns to the forms list', async ({ page }) => {
  await openPreview(page);

  await page.getByRole('button', { name: /Back to forms/ }).click();

  await expect(page).toHaveURL(/#\/forms$/);
  await expect(page.locator('.ant-table-row', { hasText: 'Donation' })).toBeVisible();
});
