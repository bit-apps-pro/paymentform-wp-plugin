import { appBaseUrl } from './appBaseUrl';

/** Dashboard path that opens the template picker for a new form (client `/myforms/new`). */
export const CREATE_FORM_PATH = '/myforms/new';

/**
 * Mints a dashboard SSO login link and opens it in a new tab, falling back to
 * `/signin` if minting fails so a CTA never dead-ends. The tab is opened
 * synchronously, before the mint request, so popup blockers still see the
 * user's click; its opener is severed before it navigates off-origin.
 *
 * @param mint     mints the link; resolves to the full single-origin dashboard url.
 * @param redirect optional dashboard path; defaults server-side to `/myforms`.
 */
export async function openLoginLink(
  mint: (redirect?: string) => Promise<{ url: string }>,
  redirect?: string,
): Promise<void> {
  const tab = window.open('about:blank', '_blank');
  if (tab) {
    tab.opener = null;
  }

  const url = await mintOrSignin(mint, redirect);

  if (tab) {
    tab.location.href = url;
  } else {
    window.open(url, '_blank', 'noopener');
  }
}

/** Resolves the minted SSO url, or the plain sign-in page when minting fails or yields a foreign url. */
async function mintOrSignin(
  mint: (redirect?: string) => Promise<{ url: string }>,
  redirect?: string,
): Promise<string> {
  const signin = `${appBaseUrl()}/signin`;
  try {
    const { url } = await mint(redirect);
    return isAppUrl(url) ? url : signin;
  } catch {
    return signin;
  }
}

/** True only for an absolute url on the dashboard's origin; the pre-opened tab shares wp-admin's origin. */
function isAppUrl(url: string): boolean {
  try {
    return new URL(url).origin === new URL(appBaseUrl()).origin;
  } catch {
    return false;
  }
}
