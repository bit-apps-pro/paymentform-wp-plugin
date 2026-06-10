/**
 * Forms are served at `<rendererBaseUrl>/<formId>` where rendererBaseUrl is the
 * tenant-scoped host computed server-side (see PluginServiceProvider::resolveRendererBaseUrl).
 *
 * Returns `null` when the localized config is missing — callers (EmbedDrawer)
 * render a "not connected" state instead of fabricating a wrong-host URL that
 * would be persisted into a published post.
 */
function rendererBase(): string | null {
  const base = window.paymentformAdmin?.rendererBaseUrl;
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

export function getGutenbergBlock(formId: string): string {
  return `<!-- wp:paymentform/form {"formId":"${formId}"} /-->`;
}
