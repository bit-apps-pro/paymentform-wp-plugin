import { test, expect } from '@playwright/test';
import { expectSuccessEnvelope, isRestResponse } from './helpers';

/**
 * Minimal typings for the globals the block editor exposes; only what the
 * evaluate() callbacks below touch.
 */
type EditorWindow = Window & {
  wp?: {
    data: {
      dispatch: (store: string) => Record<string, (...args: unknown[]) => unknown>;
      select: (store: string) => unknown;
    };
    blocks: {
      createBlock: (name: string) => unknown;
      getBlockType: (name: string) => unknown;
    };
  };
};

/** Script Module Data tag the PHP side emits for the block editor (see blocks/form-embed/edit.tsx). */
const EDITOR_MODULE_DATA_ID = 'wp-script-module-data-@paymentform/block-editor';

/**
 * Gutenberg flow: the paymentform/form block can be inserted, the editor
 * config tag carries the REST nonce, and the block's form picker is populated from the
 * enveloped /forms response (shared request() chunk). The post is never saved.
 */
test('block editor inserts paymentform/form and loads the Donation option', async ({ page }) => {
  await page.goto('/wp-admin/post-new.php');

  // Editor + our block registration ready.
  await page.waitForFunction(() => {
    const w = window as EditorWindow;
    return Boolean(
      w.wp?.data?.select('core/block-editor') && w.wp?.blocks?.getBlockType('paymentform/form')
    );
  });

  // Dismiss the welcome guide if present (fresh profiles get it once).
  await page.evaluate(() => {
    const w = window as EditorWindow;
    w.wp?.data.dispatch('core/preferences').set?.('core/edit-post', 'welcomeGuide', false);
  });

  // Editor config tag carries the REST nonce; apiFetch resolves the REST root itself.
  const editorNonce = await page.evaluate((id) => {
    const config = JSON.parse(document.getElementById(id)?.textContent || '{}') as { nonce?: string };
    return config.nonce ?? '';
  }, EDITOR_MODULE_DATA_ID);
  expect(editorNonce).not.toBe('');

  // Insert the block programmatically; its Edit component fetches /forms.
  const formsPromise = page.waitForResponse((r) => isRestResponse(r, 'forms'));
  await page.evaluate(() => {
    const w = window as EditorWindow;
    const wp = w.wp;
    if (!wp) throw new Error('wp globals missing');
    wp.data.dispatch('core/block-editor').insertBlocks?.(wp.blocks.createBlock('paymentform/form'));
  });
  await expectSuccessEnvelope(await formsPromise);

  // apiVersion 3 block: the canvas is iframed; the select gains the form option.
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const formSelect = canvas.locator('[data-type="paymentform/form"] select');
  await expect(formSelect).toBeVisible();
  await expect(formSelect.locator('option', { hasText: 'Donation' }).first()).toBeAttached();
});
