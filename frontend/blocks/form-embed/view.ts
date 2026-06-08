declare global {
  interface Window {
    paymentformView?: { rendererBaseUrl: string };
  }
}

const cfg = window.paymentformView ?? { rendererBaseUrl: 'https://render.paymentform.io' };
const rendererOrigin = new URL(cfg.rendererBaseUrl).origin;

function boot() {
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

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
