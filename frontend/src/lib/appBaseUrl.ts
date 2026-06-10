/**
 * Marketing/app host used for "go to PaymentForm" deep links from wp-admin.
 *
 * Falls back to the canonical public app when the localized config is missing
 * so CTAs always offer a working action. The trailing slash is stripped so
 * callers can append paths without doubling.
 *
 * Block-editor entry (`blocks/form-embed/edit.tsx`) keeps its own copy because
 * the block bundle is built as a self-contained entry point and cannot import
 * from `src/`.
 */
export function appBaseUrl(): string {
  const w = window as unknown as { paymentformAdmin?: { appBaseUrl?: string } };
  return (w.paymentformAdmin?.appBaseUrl ?? 'https://app.paymentform.io').replace(/\/$/, '');
}
