import { test, expect, type Page } from '@playwright/test';
import { gotoPluginPage } from './helpers';

/** Pushes the SPA down the way third-party notices do, by inserting a tall block above its root. */
async function injectTallNoticeAboveRoot(page: Page, heightPx: number): Promise<void> {
  await page.evaluate((height) => {
    const root = document.getElementById('paymentform-admin-root');
    const block = document.createElement('div');
    block.style.height = `${height}px`;
    root?.parentElement?.insertBefore(block, root);
  }, heightPx);
}

test('SPA bottom stays reachable when content is pushed above the root', async ({ page }) => {
  await gotoPluginPage(page, '#/forms');
  await injectTallNoticeAboveRoot(page, 600);

  await page.mouse.wheel(0, 5000);

  await expect
    .poll(() =>
      page.evaluate(() => {
        const rect = document.getElementById('paymentform-admin-root')!.getBoundingClientRect();
        return rect.bottom <= window.innerHeight + 1;
      })
    )
    .toBe(true);
});

for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
  test(`no outer page scrolling without injected content at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await gotoPluginPage(page, '#/forms');

    const scroll = await page.evaluate(() => {
      window.scrollTo(10_000, 0);
      const offset = { vertical: document.documentElement.scrollHeight - window.innerHeight, horizontal: window.scrollX };
      window.scrollTo(0, 0);
      return offset;
    });

    expect(scroll.vertical).toBeLessThanOrEqual(1);
    expect(scroll.horizontal).toBe(0);
  });
}
