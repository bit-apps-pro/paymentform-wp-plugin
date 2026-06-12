import { useBlockProps } from '@wordpress/block-editor';
import { SelectControl, Placeholder, Spinner, Button } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
// `@wordpress/api-fetch` (not the SPA's request() helper) keeps the block bundle
// self-contained: importing request() from src/ would re-create the cross-entry
// `request-*.js` chunk shared with the admin SPA. apiFetch routes through the
// REST root and sends credentials same-origin by default; the nonce + envelope
// behavior request() provided is reproduced locally below.
import apiFetch from '@wordpress/api-fetch';
import type { CSSProperties } from 'react';

type Attrs = { formId: string };
type SetAttrs = (next: Partial<Attrs>) => void;
type FormSummary = { id: string; name: string; status?: string };

/**
 * wp-kit wraps payloads as `{ status, code, data }`. apiFetch resolves to the
 * raw body, so unwrap to `data` here exactly as the SPA's request() does:
 * require all three envelope keys before unwrapping so a non-enveloped payload
 * that happens to carry `data` + `code` is returned as-is.
 */
function unwrap<T>(body: unknown): T {
  if (
    body !== null &&
    typeof body === 'object' &&
    'status' in body &&
    'code' in body &&
    'data' in body
  ) {
    return (body as { data: T }).data;
  }
  return body as T;
}

/**
 * apiFetch rejects with the parsed error body, which carries `message`/`code`/
 * `error_code` from the wp-kit envelope. Surface the message for the editor's
 * error placeholder, falling back to a generic string.
 */
function messageFrom(error: unknown): string {
  if (error !== null && typeof error === 'object' && 'message' in error) {
    const { message } = error as { message?: unknown };
    if (typeof message === 'string' && message !== '') return message;
  }
  return __('Failed to load forms', 'paymentform');
}

/**
 * Register the plugin's own nonce middleware once, sourced from
 * `window.paymentformAdmin.nonce`. WP core registers a REST nonce middleware in
 * the block editor already, but doing it ourselves makes the block robust on
 * any editor surface (and matches the X-WP-Nonce the SPA's request() sent).
 */
let nonceMiddlewareRegistered = false;
function ensureNonceMiddleware(): void {
  if (nonceMiddlewareRegistered) return;
  const nonce = (window as unknown as { paymentformAdmin?: { nonce?: string } }).paymentformAdmin
    ?.nonce;
  if (typeof nonce !== 'string' || nonce === '') return;
  apiFetch.use(apiFetch.createNonceMiddleware(nonce));
  nonceMiddlewareRegistered = true;
}

/**
 * Brand SVG matches the admin SPA's `src/components/Logo.tsx`. Inlined here so
 * the block bundle stays self-contained (no cross-entry import).
 */
function BrandLogo({ size = 28 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="none"
      aria-label="PaymentForm"
    >
      <rect width="100%" height="100%" rx="7" fill="#6E62E5" />
      <path
        d="M11 25V8H18.7645C20.0345 8 21.1116 8.21702 21.9957 8.65106C22.8799 9.08511 23.547 9.70402 23.9972 10.5078C24.4634 11.3116 24.6965 12.2842 24.6965 13.4255C24.6965 14.5669 24.4634 15.5475 23.9972 16.3674C23.547 17.1712 22.8799 17.7901 21.9957 18.2241C21.1116 18.6582 20.0345 18.8752 18.7645 18.8752H14.7376V22L11 25ZM14.7376 15.9816H18.1376C19.1021 15.9816 19.8336 15.7645 20.3319 15.3305C20.8463 14.8965 21.1035 14.2615 21.1035 13.4255C21.1035 12.5896 20.8463 11.9626 20.3319 11.5447C19.8336 11.1267 19.1021 10.9177 18.1376 10.9177H14.7376V15.9816Z"
        fill="white"
      />
    </svg>
  );
}

/**
 * Tenant-scoped renderer host (same source EmbedDrawer + public block-view read).
 * Returns null when the localized config is missing so the caller renders an
 * explicit "not connected" placeholder instead of a wrong-host iframe.
 */
function rendererBase(): string | null {
  const base = (window as unknown as { paymentformAdmin?: { rendererBaseUrl?: string } })
    .paymentformAdmin?.rendererBaseUrl;
  if (typeof base !== 'string' || base === '') return null;
  return base.replace(/\/$/, '');
}

/**
 * Marketing/app host used for "go create a form" CTAs. Falls back to the
 * canonical public app URL when the localized config is missing so the empty
 * state always offers a working action.
 */
function appBaseUrl(): string {
  const w = window as unknown as { paymentformAdmin?: { appBaseUrl?: string } };
  return w.paymentformAdmin?.appBaseUrl ?? 'https://app.paymentform.io';
}

/**
 * Human label for a form's lifecycle status. Returns the raw status string for
 * unknown values so future statuses still surface something (instead of a blank
 * label suffix) until the strings catch up.
 */
function statusLabel(status?: string): string {
  switch (status) {
    case 'draft':
      return __('Draft', 'paymentform');
    case 'disabled':
      return __('Disabled', 'paymentform');
    case 'archived':
      return __('Archived', 'paymentform');
    default:
      return status ?? '';
  }
}

const ACTIVE_STATUSES = new Set(['enabled', 'active', 'published']);

/**
 * SelectControl option label for a form. Active forms render as plain name;
 * non-active forms get an em-dash status suffix so editors notice draft/disabled
 * picks before they save the block.
 */
function formatLabel(f: FormSummary): string {
  if (!f.status || ACTIVE_STATUSES.has(f.status)) return f.name;
  return `${f.name} — ${statusLabel(f.status)}`;
}

const wrapperStyle: CSSProperties = {
  border: '1px solid #e5e7eb',
  borderRadius: 8,
  background: '#fff',
  overflow: 'hidden',
};

const headerStyle: CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 10,
  padding: '14px 16px',
  borderBottom: '1px solid #e5e7eb',
  background: '#fafafa',
};

const brandTextStyle: CSSProperties = {
  fontWeight: 600,
  fontSize: 14,
  color: '#111827',
};

const bodyStyle: CSSProperties = { padding: 16 };

const previewWrapStyle: CSSProperties = {
  borderTop: '1px solid #e5e7eb',
  background: '#f9fafb',
};

const iframeStyle: CSSProperties = {
  width: '100%',
  height: 500,
  border: 0,
  display: 'block',
  background: '#fff',
};

function Header() {
  return (
    <div style={headerStyle}>
      <BrandLogo />
      <span style={brandTextStyle}>{__('PaymentForm', 'paymentform')}</span>
    </div>
  );
}

export function Edit({ attributes, setAttributes }: { attributes: Attrs; setAttributes: SetAttrs }) {
  const blockProps = useBlockProps({ className: 'paymentform-embed-edit' });
  const [forms, setForms] = useState<FormSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    ensureNonceMiddleware();
    apiFetch({ path: 'paymentform/v1/forms' })
      .then((body) => setForms(unwrap<FormSummary[]>(body) ?? []))
      .catch((e) => setError(messageFrom(e)));
  }, []);

  if (error) {
    return (
      <div {...blockProps}>
        <div style={wrapperStyle}>
          <Header />
          <div style={bodyStyle}>
            <Placeholder
              icon="warning"
              label={__('Could not load forms', 'paymentform')}
              instructions={error}
            />
          </div>
        </div>
      </div>
    );
  }

  if (forms === null) {
    return (
      <div {...blockProps}>
        <div style={wrapperStyle}>
          <Header />
          <div style={{ ...bodyStyle, display: 'flex', justifyContent: 'center' }}>
            <Spinner />
          </div>
        </div>
      </div>
    );
  }

  if (forms.length === 0) {
    return (
      <div {...blockProps}>
        <div style={wrapperStyle}>
          <Header />
          <div style={bodyStyle}>
            <Placeholder
              icon={<BrandLogo size={32} />}
              label={__('No forms yet', 'paymentform')}
              instructions={__('Create a form in your PaymentForm account first.', 'paymentform')}
            >
              <Button
                variant="primary"
                href={`${appBaseUrl()}/myforms`}
                target="_blank"
                rel="noopener noreferrer"
              >
                {__('Create form', 'paymentform')}
              </Button>
            </Placeholder>
          </div>
        </div>
      </div>
    );
  }

  const selected = forms.find((f) => f.id === attributes.formId);
  const base = rendererBase();
  const previewSrc = selected && base !== null ? `${base}/${encodeURIComponent(selected.id)}` : null;

  return (
    <div {...blockProps}>
      <div style={wrapperStyle}>
        <Header />
        <div style={bodyStyle}>
          <SelectControl
            label={__('Form', 'paymentform')}
            value={attributes.formId}
            options={[
              { label: __('— Select a form —', 'paymentform'), value: '' },
              ...forms.map((f) => ({ label: formatLabel(f), value: f.id })),
            ]}
            onChange={(value) => setAttributes({ formId: value })}
            __next40pxDefaultSize
          />
        </div>
        {previewSrc && (
          <div style={previewWrapStyle}>
            <iframe
              key={previewSrc}
              src={previewSrc}
              style={iframeStyle}
              title={selected ? selected.name : __('Form preview', 'paymentform')}
              loading="lazy"
              referrerPolicy="no-referrer"
              // Sandbox so the editor preview can't submit a live payment or
              // navigate the wp-admin tab. `allow-scripts` + `allow-same-origin`
              // are enough for the renderer to function; `allow-forms` is
              // deliberately omitted.
              sandbox="allow-scripts allow-same-origin"
            />
          </div>
        )}
        {selected && base === null && (
          <div style={{ ...previewWrapStyle, padding: 16 }}>
            <Placeholder
              icon="warning"
              label={__('Preview unavailable', 'paymentform')}
              instructions={__('Connect this site to PaymentForm to preview forms here.', 'paymentform')}
            />
          </div>
        )}
      </div>
    </div>
  );
}
