import { test, expect } from '@playwright/test';
import { connectionPill, expectSuccessEnvelope, gotoPluginPage, isRestResponse } from './helpers';

/**
 * Create-account wizard: opening it runs POST /setup/diagnose immediately.
 * The flow only exists on a DISCONNECTED site (Welcome screen), and the shared
 * dev site must stay connected (disconnecting is destructive), so this spec
 * self-skips unless the header pill reads "Disconnected".
 */
test('Create new account wizard runs setup diagnose', async ({ page }) => {
  await gotoPluginPage(page);

  const pill = connectionPill(page);
  await expect(pill).toBeVisible();
  const isDisconnected = (await pill.innerText()).trim() === 'Disconnected';
  test.skip(!isDisconnected, 'requires disconnected state');

  const diagnosePromise = page.waitForResponse(
    (r) => isRestResponse(r, 'setup/diagnose') && r.request().method() === 'POST'
  );
  await page.getByRole('button', { name: 'Create new account' }).click();

  const data = await expectSuccessEnvelope<{ next?: string }>(await diagnosePromise);
  expect(data.next).toBe('ok');
});
