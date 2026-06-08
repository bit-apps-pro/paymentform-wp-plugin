import { describe, it, expect, beforeEach, afterEach } from 'vitest';

describe('paymentform block view script', () => {
  let container: HTMLDivElement;
  const rendererUrl = 'https://render.paymentform.io';

  beforeEach(() => {
    // Setup DOM
    container = document.createElement('div');
    document.body.appendChild(container);

    // Setup window.paymentformView
    (window as unknown as Record<string, unknown>).paymentformView = { rendererBaseUrl: rendererUrl };
  });

  afterEach(() => {
    if (container.parentNode) {
      document.body.removeChild(container);
    }
    // Clean up iframes and initialization flags
    document.querySelectorAll('.paymentform-embed').forEach((el) => {
      delete (el as HTMLElement & { dataset: Record<string, string> }).dataset.paymentformInitialized;
    });
  });

  function simulateViewBootup() {
    // Simulate the view script execution
    const cfg = (window as unknown as Record<string, unknown>).paymentformView as { rendererBaseUrl: string } | undefined ?? { rendererBaseUrl: rendererUrl };
    const rendererOrigin = new URL(cfg.rendererBaseUrl).origin;

    document.querySelectorAll<HTMLElement>('.paymentform-embed[data-form-id]').forEach((host) => {
      if (host.dataset.paymentformInitialized === '1') return;
      const formId = host.dataset.formId;
      if (!formId) return;

      host.dataset.paymentformInitialized = '1';
      host.style.position = 'relative';
      host.style.width = '100%';

      const iframe = document.createElement('iframe');
      iframe.src = `${cfg.rendererBaseUrl.replace(/\/$/, '')}/forms/${encodeURIComponent(formId)}`;
      iframe.style.width = '100%';
      iframe.style.border = '0';
      iframe.style.display = 'block';
      iframe.style.minHeight = '300px';
      iframe.setAttribute('loading', 'lazy');
      iframe.setAttribute('title', 'Payment form');
      iframe.setAttribute('referrerpolicy', 'no-referrer');
      iframe.setAttribute('allow', 'payment');
      iframe.dataset.formId = formId;
      host.appendChild(iframe);

      const onMessage = (ev: MessageEvent) => {
        if (ev.origin !== rendererOrigin) return;
        const data = ev.data as { type?: string; formId?: string; height?: number };
        if (data?.type !== 'paymentform:resize') return;
        if (data.formId !== formId) return;
        if (typeof data.height !== 'number' || data.height <= 0 || data.height > 10000) return;
        iframe.style.height = `${data.height}px`;
      };
      window.addEventListener('message', onMessage);
    });
  }

  it('should create an iframe for paymentform-embed div with data-form-id', () => {
    const host = document.createElement('div');
    host.className = 'paymentform-embed';
    host.dataset.formId = 'form_123';
    container.appendChild(host);

    simulateViewBootup();

    const iframe = host.querySelector('iframe');
    expect(iframe).toBeTruthy();
    expect(iframe?.src).toContain('/forms/form_123');
    expect(iframe?.style.width).toBe('100%');
  });

  it('should be idempotent (not create duplicate iframes)', () => {
    const host = document.createElement('div');
    host.className = 'paymentform-embed';
    host.dataset.formId = 'form_456';
    container.appendChild(host);

    simulateViewBootup();
    simulateViewBootup(); // Second call

    const iframes = host.querySelectorAll('iframe');
    expect(iframes.length).toBe(1);
  });

  it('should reject postMessage from wrong origin', () => {
    const host = document.createElement('div');
    host.className = 'paymentform-embed';
    host.dataset.formId = 'form_789';
    container.appendChild(host);

    simulateViewBootup();

    const iframe = host.querySelector('iframe') as HTMLIFrameElement;
    const initialHeight = iframe.style.height;

    // Simulate postMessage from wrong origin
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://evil.com',
        data: { type: 'paymentform:resize', formId: 'form_789', height: 500 },
      })
    );

    expect(iframe.style.height).toBe(initialHeight);
  });

  it('should set iframe height from valid resize message', () => {
    const host = document.createElement('div');
    host.className = 'paymentform-embed';
    host.dataset.formId = 'form_999';
    container.appendChild(host);

    simulateViewBootup();

    const iframe = host.querySelector('iframe') as HTMLIFrameElement;

    // Simulate postMessage from correct origin
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: new URL(rendererUrl).origin,
        data: { type: 'paymentform:resize', formId: 'form_999', height: 600 },
      })
    );

    expect(iframe.style.height).toBe('600px');
  });
});
