import { test, expect, type Page } from '@playwright/test';
import { gotoPluginPage, mockWorkspace } from './helpers';

const CONTROL_HEIGHT_PX = 42;
const STATUS_LABELS = ['All statuses', 'Enabled', 'Disabled'];

/** Vertical gap between the select's selected-label text and its chevron centres. */
async function selectLabelOffset(page: Page): Promise<number> {
  return page.locator('#paymentform-admin-root .ant-select').first().evaluate((select) => {
    const content = select.querySelector('.ant-select-content')!;
    const textNode = [...content.childNodes].find((node) => node.nodeType === Node.TEXT_NODE)!;
    const range = document.createRange();
    range.selectNodeContents(textNode);
    const text = range.getBoundingClientRect();
    const chevron = select.querySelector('.ant-select-suffix')!.getBoundingClientRect();
    return Math.abs(text.top + text.height / 2 - (chevron.top + chevron.height / 2));
  });
}

/** Opens the forms list as a connected workspace. */
async function openFormsList(page: Page): Promise<void> {
  await mockWorkspace(page);
  await gotoPluginPage(page, '#/forms');
  await expect(page.locator('#paymentform-admin-root .ant-select').first()).toBeVisible();
}

test('forms toolbar controls share one height', async ({ page }) => {
  await openFormsList(page);
  const root = page.locator('#paymentform-admin-root');
  const controls = [
    root.locator('.ant-input-affix-wrapper').first(),
    root.locator('.ant-select').first(),
    root.getByRole('button', { name: 'Refresh' }),
    root.getByRole('button', { name: 'Create form' }).first(),
  ];

  for (const control of controls) {
    const box = await control.boundingBox();
    expect(Math.abs((box?.height ?? 0) - CONTROL_HEIGHT_PX)).toBeLessThanOrEqual(1);
  }
});

for (const label of STATUS_LABELS) {
  test(`status select label "${label}" is vertically centred`, async ({ page }) => {
    await openFormsList(page);
    const select = page.locator('#paymentform-admin-root .ant-select').first();
    await select.click();
    await page.locator('.ant-select-item-option', { hasText: label }).click();

    expect(await selectLabelOffset(page)).toBeLessThanOrEqual(1);
  });
}

test('standalone wizard email input matches control height', async ({ page }) => {
  await mockWorkspace(page, false);
  await gotoPluginPage(page, '#/welcome');
  await page.getByRole('button', { name: /Create new account/ }).click();
  await page.getByText('use a different email').click();
  await page.getByText('Use a different email').click();

  const box = await page.locator('.ant-modal input.ant-input').first().boundingBox();
  expect(Math.abs((box?.height ?? 0) - CONTROL_HEIGHT_PX)).toBeLessThanOrEqual(1);
});
