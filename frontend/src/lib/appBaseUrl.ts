import { devEnv } from './devEnv';

/**
 * Marketing/app host used for "go to PaymentForm" deep links from wp-admin.
 *
 * Dev override (`APP_APP_BASE_URL` from frontend/.env) wins under the Vite
 * dev server; otherwise the PHP-localized config applies, falling back to
 * the canonical public app when missing so CTAs always offer a working
 * action. The trailing slash is stripped so callers can append paths
 * without doubling.
 *
 * Block-editor entry (`blocks/form-embed/edit.tsx`) keeps its own copy because
 * the block bundle is built as a self-contained entry point and cannot import
 * from `src/`.
 */
export function appBaseUrl(): string {
  const w = window as unknown as { paymentformAdmin?: { appBaseUrl?: string } };
  const base = devEnv('APP_APP_BASE_URL') ?? w.paymentformAdmin?.appBaseUrl ?? 'https://app.paymentform.io';
  return base.replace(/\/$/, '');
}
