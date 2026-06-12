export {};

declare global {
  interface Window {
    paymentformView?: { rendererBaseUrl: string };
  }
}

// Default module-data tag id for the block-view Script Module (matches the
// PHP fallback `$blockViewScriptModuleId`). If WP assigns a different id at
// runtime, the `window.paymentformView` fallback (set by the shortcode classic
// path via wp_localize_script) covers the gap.
const VIEW_MODULE_DATA_ID = 'wp-script-module-data-paymentform-form-view-script';

/**
 * Resolve the renderer base URL for the embed iframe.
 *
 * Block path: read the Script Module Data JSON tag emitted by WP.
 * Shortcode path: fall back to `window.paymentformView` (classic
 * `wp_localize_script`). Final fallback: the canonical public renderer.
 *
 * Kept value-import-free (only DOM globals) so this module works identically as
 * both a classic script (shortcode) and a Script Module (block view).
 */
function readViewConfig(): { rendererBaseUrl: string } {
  const el = document.getElementById(VIEW_MODULE_DATA_ID);
  if (el?.textContent) {
    try {
      const parsed = JSON.parse(el.textContent) as { rendererBaseUrl?: string };
      if (typeof parsed.rendererBaseUrl === 'string' && parsed.rendererBaseUrl !== '') {
        return { rendererBaseUrl: parsed.rendererBaseUrl };
      }
    } catch {
      /* fall through to the window/default fallback */
    }
  }
  return window.paymentformView ?? { rendererBaseUrl: 'https://render.paymentform.io' };
}

/**
 * Build the iframe src for a given form id. Exported so the test suite can
 * assert the exact URL shape without re-implementing it.
 */
export function buildFormSrc(rendererBaseUrl: string, formId: string): string {
  // `?embedded=true` flips the renderer into iframe mode: it strips its own
  // page chrome and starts emitting `paymentform:height` postMessages so the
  // host can auto-fit the iframe to form content (seamless embed).
  return `${rendererBaseUrl.replace(/\/$/, '')}/${encodeURIComponent(formId)}?embedded=true`;
}

export function bootBlockView(): void {
  const cfg = readViewConfig();
  const rendererOrigin = new URL(cfg.rendererBaseUrl).origin;

  document.querySelectorAll<HTMLElement>('.paymentform-embed[data-form-id]').forEach((host) => {
    if (host.dataset.paymentformInitialized === '1') return;
    const formId = host.dataset.formId;
    if (!formId) return;

    host.dataset.paymentformInitialized = '1';
    host.style.position = 'relative';
    host.style.width = '100%';

    const iframe = document.createElement('iframe');
    // Form is served at `<tenant-base>/<form-id>` — `rendererBaseUrl` is the
    // tenant-scoped host computed server-side (see
    // PluginServiceProvider::resolveRendererBaseUrl), no `/forms/` prefix.
    iframe.src = buildFormSrc(cfg.rendererBaseUrl, formId);
    iframe.style.width = '100%';
    iframe.style.border = '0';
    iframe.style.display = 'block';
    iframe.style.minHeight = '300px';
    iframe.setAttribute('loading', 'lazy');
    iframe.setAttribute('title', 'Payment form');
    iframe.setAttribute('referrerpolicy', 'no-referrer');
    iframe.setAttribute('allow', 'payment');
    // Seamless embed: renderer auto-reports height via postMessage so the host
    // grows to fit content. Disable the iframe's own scrollbar to avoid double
    // scroll regions on long forms.
    iframe.setAttribute('scrolling', 'no');
    iframe.dataset.formId = formId;
    host.appendChild(iframe);

    const onMessage = (ev: MessageEvent) => {
      // Defence in depth: origin pins the sender to the configured renderer
      // host; source pins it to *this* iframe's window so multiple embeds on
      // the same page (or unrelated postMessage chatter from same-origin
      // frames) can't cross-resize each other.
      if (ev.origin !== rendererOrigin) return;
      if (ev.source !== iframe.contentWindow) return;
      const data = ev.data as { type?: string; height?: number };
      if (data?.type !== 'paymentform:height') return;
      if (typeof data.height !== 'number' || data.height <= 0 || data.height > 10000) return;
      iframe.style.height = `${data.height}px`;
    };
    window.addEventListener('message', onMessage);
  });
}

// Auto-boot only in the real browser bundle. Tests import `bootBlockView` directly
// and run it explicitly per case; auto-booting at import-time inside vitest would
// attach to a half-built happy-dom and leak across test cases. The Vitest flag is
// set by `vitest.setup.ts`.
const isVitest = (globalThis as unknown as { __VITEST__?: boolean }).__VITEST__ === true;
if (typeof document !== 'undefined' && !isVitest) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootBlockView, { once: true });
  } else {
    bootBlockView();
  }
}
