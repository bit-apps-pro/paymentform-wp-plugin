import { describe, it, expect, beforeEach, vi } from 'vitest';
import { CREATE_FORM_PATH, openLoginLink } from '../../lib/openLoginLink';

/** A stand-in for the pre-opened tab window.open returns. */
function fakeTab(): Window {
  return { opener: {}, location: { href: 'about:blank' } } as unknown as Window;
}

describe('openLoginLink', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('opens the tab before minting resolves so popup blockers allow it', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(fakeTab());
    let resolveMint!: (value: { url: string }) => void;
    const mint = vi.fn(() => new Promise<{ url: string }>((resolve) => { resolveMint = resolve; }));

    const pending = openLoginLink(mint, '/myforms');

    expect(open).toHaveBeenCalledWith('about:blank', '_blank');
    resolveMint({ url: 'https://app.paymentform.io/auth/sso?ticket=t' });
    await pending;
  });

  it('severs the opener and navigates the tab to the minted url', async () => {
    const tab = fakeTab();
    vi.spyOn(window, 'open').mockReturnValue(tab);
    const url = 'https://app.paymentform.io/auth/sso?ticket=abc123';
    const mint = vi.fn().mockResolvedValue({ url });

    await openLoginLink(mint, '/build/42');

    expect(mint).toHaveBeenCalledWith('/build/42');
    expect(tab.opener).toBeNull();
    expect(tab.location.href).toBe(url);
  });

  it('navigates the tab to /signin when minting fails', async () => {
    const tab = fakeTab();
    vi.spyOn(window, 'open').mockReturnValue(tab);
    const mint = vi.fn().mockRejectedValue(new Error('DISCONNECTED'));

    await openLoginLink(mint, '/myforms');

    // appBaseUrl() resolves to the seeded wpModuleData.appBaseUrl in tests.
    expect(tab.location.href).toBe('https://app.paymentform.io/signin');
  });

  for (const minted of ['javascript:alert(document.domain)', 'https://evil.example/auth/sso?ticket=t']) {
    it(`refuses a minted url outside the app origin (${minted.split(':')[0]})`, async () => {
      const tab = fakeTab();
      vi.spyOn(window, 'open').mockReturnValue(tab);

      await openLoginLink(vi.fn().mockResolvedValue({ url: minted }), '/myforms');

      expect(tab.location.href).toBe('https://app.paymentform.io/signin');
    });
  }

  it('falls back to a noopener open when the blank tab is blocked', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    const url = 'https://app.paymentform.io/auth/sso?ticket=x';
    const mint = vi.fn().mockResolvedValue({ url });

    await openLoginLink(mint);

    expect(mint).toHaveBeenCalledWith(undefined);
    expect(open).toHaveBeenLastCalledWith(url, '_blank', 'noopener');
  });

  it('targets the client route that opens the template picker for new forms', () => {
    expect(CREATE_FORM_PATH).toBe('/myforms/new');
  });
});
