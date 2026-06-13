import { appBaseUrl } from './appBaseUrl';

/**
 * Mints a dashboard SSO login link and opens it in a new tab, falling back to
 * the plain `/signin` page if minting fails (spec §L3) so a CTA never
 * dead-ends. Shared by all four wp-admin deep-link sites (UserMenu, the two
 * Forms "Create form" buttons, the Forms edit button, and the wizard success
 * step) so the mint + open + fallback logic lives in exactly one place.
 *
 * `mint` is the bound `useLoginLink().mutateAsync` — passed in rather than
 * called here because this helper is not a React hook. Opens are `noopener`:
 * the new tab gets no `window.opener` reference back to wp-admin.
 *
 * @param mint     mints the link; resolves to the full single-origin dashboard url.
 * @param redirect optional dashboard path; defaults server-side to `/myforms`.
 */
export async function openLoginLink(
  mint: (redirect?: string) => Promise<{ url: string }>,
  redirect?: string,
): Promise<void> {
  try {
    const { url } = await mint(redirect);
    window.open(url, '_blank', 'noopener');
  } catch {
    window.open(`${appBaseUrl()}/signin`, '_blank', 'noopener');
  }
}
