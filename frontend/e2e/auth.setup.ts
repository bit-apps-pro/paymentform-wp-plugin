import { test as setup, expect } from '@playwright/test';
import { AUTH_STATE_PATH, WP_PASS, WP_USER } from './helpers';

/**
 * Logs into WordPress via the wp-login.php form and saves the authenticated
 * cookies as storageState; the chromium project depends on this so every spec
 * starts logged in without repeating the form dance.
 */
setup('authenticate via wp-login.php', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(WP_USER);
  await page.locator('#user_pass').fill(WP_PASS);
  await page.locator('#wp-submit').click();

  await page.waitForURL('**/wp-admin/**');
  await expect(page.locator('#wpadminbar')).toBeVisible();

  await page.context().storageState({ path: AUTH_STATE_PATH });
});
