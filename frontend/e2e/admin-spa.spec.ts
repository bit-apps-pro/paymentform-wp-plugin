import { test, expect } from '@playwright/test';
import { connectionPill, expectSuccessEnvelope, gotoPluginPage, isRestResponse } from './helpers';

/**
 * Connected admin SPA: header pill, forms table, and the wp-kit envelope
 * contract on the two REST reads the page performs (/status and /forms).
 */
test('plugin page shows Connected and lists Donation via enveloped REST', async ({ page }) => {
  const statusPromise = page.waitForResponse((r) => isRestResponse(r, 'status'));
  const formsPromise = page.waitForResponse((r) => isRestResponse(r, 'forms'));

  await gotoPluginPage(page);
  const [statusRes, formsRes] = await Promise.all([statusPromise, formsPromise]);

  // Both requests must carry the REST nonce header (auth middleware input).
  for (const res of [statusRes, formsRes]) {
    const headers = await res.request().allHeaders();
    expect(headers['x-wp-nonce']).toBeTruthy();
  }

  // Envelope contract: 200 + { status: 'success', code: 'SUCCESS', data }.
  await expectSuccessEnvelope(statusRes);
  const forms = await expectSuccessEnvelope<unknown[]>(formsRes);
  // Flatten contract: /forms data is the bare array, not { forms: [...] }.
  expect(Array.isArray(forms)).toBe(true);

  await expect(connectionPill(page)).toHaveText('Connected');
  await expect(page.getByRole('menuitem', { name: 'Forms' })).toBeVisible();
  await expect(page.getByRole('menuitem', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'Donation' })).toBeVisible();
});
