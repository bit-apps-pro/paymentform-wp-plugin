import { describe, it, expect, beforeEach, vi } from 'vitest';
import { openLoginLink } from '../../lib/openLoginLink';

describe('openLoginLink', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('opens the minted url in a new noopener tab on success', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    const url = 'https://app.paymentform.io/auth/sso?ticket=abc123';
    const mint = vi.fn().mockResolvedValue({ url });

    await openLoginLink(mint, '/build/42');

    expect(mint).toHaveBeenCalledWith('/build/42');
    expect(open).toHaveBeenCalledWith(url, '_blank', 'noopener');
  });

  it('falls back to the /signin page when minting fails', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    const mint = vi.fn().mockRejectedValue(new Error('DISCONNECTED'));

    await openLoginLink(mint, '/myforms');

    // appBaseUrl() resolves to the seeded wpModuleData.appBaseUrl in tests.
    expect(open).toHaveBeenCalledWith('https://app.paymentform.io/signin', '_blank', 'noopener');
  });

  it('passes an undefined redirect straight through (server defaults to /myforms)', async () => {
    vi.spyOn(window, 'open').mockReturnValue(null);
    const mint = vi.fn().mockResolvedValue({ url: 'https://app.paymentform.io/auth/sso?ticket=x' });

    await openLoginLink(mint);

    expect(mint).toHaveBeenCalledWith(undefined);
  });
});
