import { wpModuleData } from '../../utils/wpData';

/**
 * Forms are served at `<rendererBaseUrl>/<formId>` where rendererBaseUrl is the
 * tenant-scoped host computed server-side (see
 * PluginServiceProvider::resolveRendererBaseUrl) and delivered through the admin
 * Script Module Data config. Under the Vite dev server it is already derived
 * from the dev `APP_APP_BASE_URL` app host, so no separate renderer override is
 * needed here.
 *
 * Returns `null` when the localized config is missing — callers (CopyEmbedButton,
 * FormPreview) render a "not connected" state instead of fabricating a wrong-host URL that
 * would be persisted into a published post.
 */
function rendererBase(): string | null {
  const base = wpModuleData.rendererBaseUrl;
  if (typeof base !== 'string' || base === '') return null;
  return base.replace(/\/$/, '');
}

export function getEmbedUrl(formId: string): string | null {
  const base = rendererBase();
  return base === null ? null : `${base}/${encodeURIComponent(formId)}`;
}

export function getShortcode(formId: string): string {
  return `[paymentform id="${formId}"]`;
}

/** Block markup exactly as the static block's save() serializes it; a bare comment parses as invalid and renders nothing. */
export function getGutenbergBlock(formId: string): string {
  return (
    `<!-- wp:paymentform/form {"formId":"${formId}"} -->\n` +
    `<div class="wp-block-paymentform-form paymentform-embed" data-form-id="${formId}"></div>\n` +
    '<!-- /wp:paymentform/form -->'
  );
}
