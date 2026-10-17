import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { bootBlockView, buildFormSrc, HEIGHT_FALLBACK_MS, MAX_REVEAL_WAIT_MS, REVEAL_SETTLE_MS } from './view';

/**
 * Tests import the REAL `view.ts` module — no inline reimplementation. If the URL
 * shape (`<base>/<formId>` vs `<base>/forms/<formId>`) drifts in production, these
 * tests fail fast instead of asserting against a stale stub.
 */
describe('paymentform block view', () => {
  let container: HTMLDivElement;
  const rendererUrl = 'https://render.paymentform.io';

  beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    (window as unknown as Record<string, unknown>).paymentformView = { rendererBaseUrl: rendererUrl };
  });

  afterEach(() => {
    if (container.parentNode) {
      document.body.removeChild(container);
    }
    document.querySelectorAll('.paymentform-embed').forEach((el) => {
      delete (el as HTMLElement & { dataset: Record<string, string> }).dataset.paymentformInitialized;
    });
  });

  describe('buildFormSrc', () => {
    it('emits <base>/<formId>?embedded=true with no /forms/ prefix', () => {
      expect(buildFormSrc('https://tenant.paymentform.io', 'form_123')).toBe(
        'https://tenant.paymentform.io/form_123?embedded=true',
      );
    });

    it('strips trailing slash from base', () => {
      expect(buildFormSrc('https://tenant.paymentform.io/', 'form_abc')).toBe(
        'https://tenant.paymentform.io/form_abc?embedded=true',
      );
    });

    it('URL-encodes form ids that contain reserved chars', () => {
      expect(buildFormSrc('https://tenant.paymentform.io', 'a b/c')).toBe(
        'https://tenant.paymentform.io/a%20b%2Fc?embedded=true',
      );
    });
  });

  describe('bootBlockView', () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it('creates iframe for paymentform-embed div with data-form-id', () => {
      const host = document.createElement('div');
      host.className = 'paymentform-embed';
      host.dataset.formId = 'form_123';
      container.appendChild(host);

      bootBlockView();

      const iframe = host.querySelector('iframe');
      expect(iframe).toBeTruthy();
      // Asserts the new shape — no /forms/ prefix, plus embedded flag.
      expect(iframe?.src).toBe(`${rendererUrl}/form_123?embedded=true`);
      expect(iframe?.style.width).toBe('100%');
    });

    it('prefers the script-module-data tag over window.paymentformView', () => {
      const tagBase = 'https://module-tag.paymentform.io';
      const tag = document.createElement('script');
      tag.type = 'application/json';
      tag.id = 'wp-script-module-data-paymentform-form-view-script';
      tag.textContent = JSON.stringify({ rendererBaseUrl: tagBase });
      document.head.appendChild(tag);

      try {
        const host = document.createElement('div');
        host.className = 'paymentform-embed';
        host.dataset.formId = 'form_tag';
        container.appendChild(host);

        bootBlockView();

        const iframe = host.querySelector('iframe');
        // The JSON tag wins over the window.paymentformView set in beforeEach.
        expect(iframe?.src).toBe(`${tagBase}/form_tag?embedded=true`);
      } finally {
        tag.remove();
      }
    });

    it('is idempotent (no duplicate iframes on second call)', () => {
      const host = document.createElement('div');
      host.className = 'paymentform-embed';
      host.dataset.formId = 'form_456';
      container.appendChild(host);

      bootBlockView();
      bootBlockView();

      expect(host.querySelectorAll('iframe').length).toBe(1);
    });

    /** Boots one embed and returns its iframe. */
    function bootSingleEmbed(formId: string): HTMLIFrameElement {
      const host = document.createElement('div');
      host.className = 'paymentform-embed';
      host.dataset.formId = formId;
      container.appendChild(host);
      bootBlockView();
      return host.querySelector('iframe') as HTMLIFrameElement;
    }

    /** Dispatches a size message as if posted by `source` from `origin`, measured at `frameWidth`. */
    function postHeight(origin: string, source: unknown, height: number, width?: unknown, frameWidth?: number): void {
      const ev = new MessageEvent('message', {
        origin,
        data: { type: 'paymentform:height', height, width, frameWidth },
      });
      Object.defineProperty(ev, 'source', { value: source });
      window.dispatchEvent(ev);
    }

    it('applies height from a redirected https renderer origin when posted by this iframe', () => {
      // The renderer 302s tenant subdomains to custom/bound domains, so the frame's final origin can differ.
      const iframe = bootSingleEmbed('form_redirected');

      postHeight('https://forms.customer.example', iframe.contentWindow, 640);
      vi.advanceTimersByTime(MAX_REVEAL_WAIT_MS);

      expect(iframe.style.height).toBe('640px');
    });

    it('sizes the frame to the form card the renderer reports, centred', () => {
      const iframe = bootSingleEmbed('form_card');

      postHeight(rendererUrl, iframe.contentWindow, 1305, 800);
      vi.advanceTimersByTime(REVEAL_SETTLE_MS);

      expect(iframe.style.height).toBe('1305px');
      expect(iframe.style.minHeight).toBe('0px');
      expect(iframe.style.width).toBe('800px');
      expect(iframe.style.maxWidth).toBe('100%');
      expect([iframe.style.marginLeft, iframe.style.marginRight]).toEqual(['auto', 'auto']);
    });

    it.each([undefined, 0, -5, 20000, '800'])('keeps the full column width for a card width of %s', (width) => {
      const iframe = bootSingleEmbed('form_fluid');

      postHeight(rendererUrl, iframe.contentWindow, 1305, width);
      vi.advanceTimersByTime(MAX_REVEAL_WAIT_MS);

      expect(iframe.style.height).toBe('1305px');
      expect(iframe.style.width).toBe('100%');
    });

    describe('while the form settles', () => {
      it('stays hidden at its placeholder until the reported size holds still, then shows at that size', () => {
        const iframe = bootSingleEmbed('form_settling');

        postHeight(rendererUrl, iframe.contentWindow, 1001);
        postHeight(rendererUrl, iframe.contentWindow, 1732, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS - 1);
        postHeight(rendererUrl, iframe.contentWindow, 1610, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS - 1);

        expect(iframe.style.opacity).toBe('0');
        expect(iframe.style.height).toBe('');
        expect(iframe.style.width).toBe('800px');

        vi.advanceTimersByTime(1);

        expect(iframe.style.opacity).toBe('1');
        expect(iframe.style.height).toBe('1610px');
      });

      it('does not count a size as settled before the form card has reported', () => {
        const iframe = bootSingleEmbed('form_loading');

        postHeight(rendererUrl, iframe.contentWindow, 1001);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS * 2);

        expect(iframe.style.opacity).toBe('0');

        postHeight(rendererUrl, iframe.contentWindow, 1610, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS);

        expect(iframe.style.opacity).toBe('1');
        expect(iframe.style.height).toBe('1610px');
      });

      it('settles a card that reports the same height as its loading state', () => {
        const iframe = bootSingleEmbed('form_same_height');

        postHeight(rendererUrl, iframe.contentWindow, 1610);
        postHeight(rendererUrl, iframe.contentWindow, 1610, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS);

        expect(iframe.style.opacity).toBe('1');
      });

      it('waits for a height the form measured at the width the frame now has', () => {
        const iframe = bootSingleEmbed('form_resized');
        Object.defineProperty(iframe, 'clientWidth', { get: () => (iframe.style.width === '800px' ? 800 : 1100) });

        postHeight(rendererUrl, iframe.contentWindow, 1732, 800, 1100);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS * 2);

        expect(iframe.style.opacity).toBe('0');

        postHeight(rendererUrl, iframe.contentWindow, 1610, 800, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS);

        expect(iframe.style.opacity).toBe('1');
        expect(iframe.style.height).toBe('1610px');
      });

      it('does not keep waiting on a re-sent unchanged size', () => {
        const iframe = bootSingleEmbed('form_polling');

        postHeight(rendererUrl, iframe.contentWindow, 1610, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS - 50);
        postHeight(rendererUrl, iframe.contentWindow, 1610, 800);
        vi.advanceTimersByTime(50);

        expect(iframe.style.opacity).toBe('1');
      });

      it('shows the form at its latest size once the longest wait is over, though it keeps changing', () => {
        const iframe = bootSingleEmbed('form_restless');

        for (let elapsed = 0; elapsed < MAX_REVEAL_WAIT_MS; elapsed += 100) {
          postHeight(rendererUrl, iframe.contentWindow, 1000 + elapsed);
          vi.advanceTimersByTime(100);
        }

        expect(iframe.style.opacity).toBe('1');
        expect(iframe.style.height).toBe(`${1000 + MAX_REVEAL_WAIT_MS - 100}px`);
      });

      it('follows the form at once after it is shown', () => {
        const iframe = bootSingleEmbed('form_growing');
        postHeight(rendererUrl, iframe.contentWindow, 1610, 800);
        vi.advanceTimersByTime(REVEAL_SETTLE_MS);

        postHeight(rendererUrl, iframe.contentWindow, 1900, 800);

        expect(iframe.style.height).toBe('1900px');
      });
    });

    it('rejects a non-https origin other than the configured renderer', () => {
      const iframe = bootSingleEmbed('form_789');
      const initialHeight = iframe.style.height;

      postHeight('http://evil.example', iframe.contentWindow, 500);

      expect(iframe.style.height).toBe(initialHeight);
    });

    describe('when no height message arrives', () => {
      beforeEach(() => {
        vi.useFakeTimers();
      });

      afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
      });

      it('falls back to a scrollable iframe and warns once', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        const iframe = bootSingleEmbed('form_silent');

        iframe.dispatchEvent(new Event('load'));
        vi.advanceTimersByTime(HEIGHT_FALLBACK_MS);

        expect(iframe.getAttribute('scrolling')).toBe('auto');
        expect(iframe.style.height).toBe('80vh');
        expect(iframe.style.opacity).toBe('1');
        expect(warn).toHaveBeenCalledTimes(1);
      });

      it('does not fall back while a lazy iframe has not loaded yet', () => {
        const iframe = bootSingleEmbed('form_lazy');

        vi.advanceTimersByTime(HEIGHT_FALLBACK_MS * 3);

        expect(iframe.getAttribute('scrolling')).toBe('no');
      });

      it('keeps the auto-fit iframe once a height message arrived', () => {
        const iframe = bootSingleEmbed('form_talks');

        iframe.dispatchEvent(new Event('load'));
        postHeight(new URL(rendererUrl).origin, iframe.contentWindow, 900);
        vi.advanceTimersByTime(HEIGHT_FALLBACK_MS);

        expect(iframe.getAttribute('scrolling')).toBe('no');
        expect(iframe.style.height).toBe('900px');
      });
    });

    it('sets iframe height from valid paymentform:height message', () => {
      const host = document.createElement('div');
      host.className = 'paymentform-embed';
      host.dataset.formId = 'form_999';
      container.appendChild(host);

      bootBlockView();

      const iframe = host.querySelector('iframe') as HTMLIFrameElement;

      const ev = new MessageEvent('message', {
        origin: new URL(rendererUrl).origin,
        data: { type: 'paymentform:height', height: 800 },
      });
      Object.defineProperty(ev, 'source', { value: iframe.contentWindow });
      window.dispatchEvent(ev);
      vi.advanceTimersByTime(MAX_REVEAL_WAIT_MS);

      expect(iframe.style.height).toBe('800px');
    });

    it('ignores paymentform:height from a different iframe contentWindow', () => {
      const hostA = document.createElement('div');
      hostA.className = 'paymentform-embed';
      hostA.dataset.formId = 'form_aaa';
      container.appendChild(hostA);

      const hostB = document.createElement('div');
      hostB.className = 'paymentform-embed';
      hostB.dataset.formId = 'form_bbb';
      container.appendChild(hostB);

      bootBlockView();

      const iframeA = hostA.querySelector('iframe') as HTMLIFrameElement;
      const iframeB = hostB.querySelector('iframe') as HTMLIFrameElement;
      const initialHeightA = iframeA.style.height;

      // happy-dom returns the same null-ish sentinel for both iframes'
      // contentWindow until the document loads, which would let the source
      // check pass for both listeners by coincidence. Force distinct stubs
      // so the per-iframe scoping is actually exercised.
      const stubA = { name: 'stubA' } as unknown as Window;
      const stubB = { name: 'stubB' } as unknown as Window;
      Object.defineProperty(iframeA, 'contentWindow', { value: stubA, configurable: true });
      Object.defineProperty(iframeB, 'contentWindow', { value: stubB, configurable: true });

      // Message claims to be from the renderer origin and carries a valid
      // payload, but its `source` is iframe B's contentWindow. The listener
      // attached for iframe A must drop it; the listener for B should apply it.
      const ev = new MessageEvent('message', {
        origin: new URL(rendererUrl).origin,
        data: { type: 'paymentform:height', height: 800 },
      });
      Object.defineProperty(ev, 'source', { value: stubB });
      window.dispatchEvent(ev);
      vi.advanceTimersByTime(MAX_REVEAL_WAIT_MS);

      expect(iframeA.style.height).toBe(initialHeightA);
      expect(iframeB.style.height).toBe('800px');
    });

    it('rejects out-of-bounds heights', () => {
      const host = document.createElement('div');
      host.className = 'paymentform-embed';
      host.dataset.formId = 'form_oob';
      container.appendChild(host);

      bootBlockView();

      const iframe = host.querySelector('iframe') as HTMLIFrameElement;
      const initialHeight = iframe.style.height;

      const tooBig = new MessageEvent('message', {
        origin: new URL(rendererUrl).origin,
        data: { type: 'paymentform:height', height: 10001 },
      });
      Object.defineProperty(tooBig, 'source', { value: iframe.contentWindow });
      window.dispatchEvent(tooBig);
      expect(iframe.style.height).toBe(initialHeight);

      const nonPositive = new MessageEvent('message', {
        origin: new URL(rendererUrl).origin,
        data: { type: 'paymentform:height', height: 0 },
      });
      Object.defineProperty(nonPositive, 'source', { value: iframe.contentWindow });
      window.dispatchEvent(nonPositive);
      expect(iframe.style.height).toBe(initialHeight);
    });
  });
});
