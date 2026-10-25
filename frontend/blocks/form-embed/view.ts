export {};

declare global {
  interface Window {
    paymentformView?: { rendererBaseUrl: string };
  }
}

// Prefix of the block-view Script Module Data tag id. WP derives the actual id
// from the registered view module id and appends a suffix (e.g. `-module`), so
// the tag is `wp-script-module-data-paymentform-form-view-script-module` — we
// match by prefix rather than an exact id to stay robust to that suffix. The
// `window.paymentformView` fallback (shortcode classic path via
// wp_localize_script) still covers the shortcode case.
const VIEW_MODULE_DATA_ID_PREFIX = 'wp-script-module-data-paymentform-form-view-script';

/** How long to wait for the renderer's first height message before falling back to a scrollable frame. */
export const HEIGHT_FALLBACK_MS = 4000;
const FALLBACK_HEIGHT = '80vh';
const MAX_EMBED_HEIGHT_PX = 10000;

/**
 * Resolve the renderer base URL for the embed iframe.
 *
 * Block path: read the Script Module Data JSON tag emitted by WP.
 * Shortcode path: fall back to `window.paymentformView` (classic
 * `wp_localize_script`). Null when neither carries a host (site not connected).
 *
 * Kept value-import-free (only DOM globals) so this module works identically as
 * both a classic script (shortcode) and a Script Module (block view).
 */
function readViewConfig(): { rendererBaseUrl: string } | null {
  const el = document.querySelector(
    `script[type="application/json"][id^="${VIEW_MODULE_DATA_ID_PREFIX}"]`,
  );
  if (el?.textContent) {
    try {
      const parsed = JSON.parse(el.textContent) as { rendererBaseUrl?: string };
      if (typeof parsed.rendererBaseUrl === 'string' && parsed.rendererBaseUrl !== '') {
        return { rendererBaseUrl: parsed.rendererBaseUrl };
      }
    } catch {
      /* fall through to the classic-global fallback */
    }
  }
  const classic = window.paymentformView?.rendererBaseUrl;
  return classic ? { rendererBaseUrl: classic } : null;
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

/** Accepts the configured renderer origin or any https origin it may have redirected to. */
function isTrustedRendererOrigin(origin: string, rendererOrigin: string): boolean {
  return origin === rendererOrigin || origin.startsWith('https://');
}

/** Lets a silent renderer's form stay usable: fixed tall frame with its own scrollbar. */
function applyScrollableFallback(iframe: HTMLIFrameElement): void {
  iframe.setAttribute('scrolling', 'auto');
  iframe.style.height = FALLBACK_HEIGHT;
  console.warn(`PaymentForm: no height from form ${iframe.dataset.formId ?? ''}; using a scrollable embed.`);
}

/** Replaces every `.paymentform-embed` placeholder with an auto-sizing renderer iframe. */
export function bootBlockView(): void {
  const cfg = readViewConfig();
  // No connected workspace means no known renderer host: leave placeholders empty.
  if (!cfg) return;
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
    iframe.style.maxWidth = '100%';
    iframe.style.border = '0';
    iframe.style.padding = '0';
    // A dark-scheme theme would otherwise paint the frame's canvas opaque beside the centred form card.
    iframe.style.colorScheme = 'normal';
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

    let receivedHeight = false;
    let fallbackTimer: number | undefined;
    // Start the clock on load: a lazy iframe below the fold may not fetch for a long time.
    iframe.addEventListener(
      'load',
      () => {
        if (!receivedHeight) fallbackTimer = window.setTimeout(() => applyScrollableFallback(iframe), HEIGHT_FALLBACK_MS);
      },
      { once: true },
    );

    const onMessage = (ev: MessageEvent) => {
      // Source pins the sender to *this* iframe's window, so other frames and
      // other embeds can't resize it. The origin is not pinned to the configured
      // host: the renderer 302s tenant subdomains to custom/bound domains.
      if (ev.source !== iframe.contentWindow) return;
      if (!isTrustedRendererOrigin(ev.origin, rendererOrigin)) return;
      const data = ev.data as { type?: string; height?: number };
      if (data?.type !== 'paymentform:height') return;
      if (typeof data.height !== 'number' || data.height <= 0 || data.height > MAX_EMBED_HEIGHT_PX) return;
      receivedHeight = true;
      window.clearTimeout(fallbackTimer);
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
