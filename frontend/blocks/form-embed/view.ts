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
const MAX_EMBED_WIDTH_PX = 4000;
/** How long the reported size must hold still before a loading form is shown. */
export const REVEAL_SETTLE_MS = 150;
/** Longest a form stays hidden once it has started reporting its size. */
// Must stay below the renderer's CLIP_GRACE_MS (2s), or the form shows its own scrollbar while hidden.
export const MAX_REVEAL_WAIT_MS = 1500;
const REVEAL_TRANSITION = 'opacity 200ms ease';

interface FormSize {
  height: number;
  width?: unknown;
  frameWidth?: unknown;
  loading?: unknown;
}

interface FormSizer {
  onSize(size: FormSize): void;
  reveal(): void;
}

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

/** Sizes the frame to the renderer's form card, centred, so the form sits in the page like native content. */
function fitFormWidth(iframe: HTMLIFrameElement, width: unknown): void {
  if (typeof width !== 'number' || !(width > 0) || width > MAX_EMBED_WIDTH_PX) return;
  iframe.style.width = `${width}px`;
  iframe.style.marginLeft = 'auto';
  iframe.style.marginRight = 'auto';
}

/** Whether the form measured its height at the frame's current width; older renderers don't say, so trust them. */
function measuredAtFrameWidth(iframe: HTMLIFrameElement, frameWidth: unknown): boolean {
  return typeof frameWidth !== 'number' || Math.abs(frameWidth - iframe.clientWidth) <= 1;
}

/** Holds a loading form hidden until its reported size stops changing, then shows it at that size in one step. */
function createFormSizer(iframe: HTMLIFrameElement): FormSizer {
  let revealed = false;
  let latestHeight = 0;
  let settlingHeight: number | undefined;
  let settleTimer: number | undefined;
  let maxWaitTimer: number | undefined;

  iframe.style.opacity = '0';
  iframe.style.transition = REVEAL_TRANSITION;
  iframe.setAttribute('inert', '');

  /** Sets the frame to the form's height, dropping the loading floor. */
  const applyHeight = (height: number) => {
    iframe.style.height = `${height}px`;
    iframe.style.minHeight = '0px';
  };

  /** Shows the frame once, at the latest reported height. */
  const reveal = () => {
    if (revealed) return;
    revealed = true;
    window.clearTimeout(settleTimer);
    window.clearTimeout(maxWaitTimer);
    if (latestHeight) applyHeight(latestHeight);
    iframe.removeAttribute('inert');
    iframe.style.opacity = '1';
  };

  /** Takes one size report: follows it once shown, otherwise waits for it to settle. */
  const onSize = ({ height, width, frameWidth, loading }: FormSize) => {
    // Width goes on at once: the form re-measures its height at the frame's final width.
    fitFormWidth(iframe, width);
    if (revealed) {
      applyHeight(height);
      return;
    }
    latestHeight = height;
    maxWaitTimer ??= window.setTimeout(reveal, MAX_REVEAL_WAIT_MS);
    // A loading skeleton's height is a placeholder, and one measured at another frame width is stale.
    const settling = loading !== true && measuredAtFrameWidth(iframe, frameWidth) ? height : undefined;
    if (settling === settlingHeight) return;
    settlingHeight = settling;
    window.clearTimeout(settleTimer);
    if (settling !== undefined) settleTimer = window.setTimeout(reveal, REVEAL_SETTLE_MS);
  };

  return { onSize, reveal };
}

/** Replaces every `.paymentform-embed` placeholder with an auto-sizing renderer iframe. */
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
    iframe.style.maxWidth = '100%';
    iframe.style.border = '0';
    iframe.style.padding = '0';
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

    const sizer = createFormSizer(iframe);
    let receivedHeight = false;
    let fallbackTimer: number | undefined;
    // Start the clock on load: a lazy iframe below the fold may not fetch for a long time.
    iframe.addEventListener(
      'load',
      () => {
        if (!receivedHeight) {
          fallbackTimer = window.setTimeout(() => {
            sizer.reveal();
            applyScrollableFallback(iframe);
          }, HEIGHT_FALLBACK_MS);
        }
      },
      { once: true },
    );

    const onMessage = (ev: MessageEvent) => {
      // Source pins the sender to *this* iframe's window, so other frames and
      // other embeds can't resize it. The origin is not pinned to the configured
      // host: the renderer 302s tenant subdomains to custom/bound domains.
      if (ev.source !== iframe.contentWindow) return;
      if (!isTrustedRendererOrigin(ev.origin, rendererOrigin)) return;
      const data = ev.data as { type?: string; height?: number } & Omit<FormSize, 'height'>;
      if (data?.type !== 'paymentform:height') return;
      if (typeof data.height !== 'number' || data.height <= 0 || data.height > MAX_EMBED_HEIGHT_PX) return;
      receivedHeight = true;
      window.clearTimeout(fallbackTimer);
      sizer.onSize({ height: data.height, width: data.width, frameWidth: data.frameWidth, loading: data.loading });
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
