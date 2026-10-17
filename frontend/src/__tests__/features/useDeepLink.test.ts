import { describe, it, expect, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useDeepLink } from '../../features/auth/useDeepLink';
import { openLoginLink } from '../../lib/openLoginLink';

const mint = vi.fn();
vi.mock('../../features/auth/useLoginLink', () => ({
  useLoginLink: () => ({ mutateAsync: mint }),
}));
vi.mock('../../lib/openLoginLink', () => ({ openLoginLink: vi.fn() }));

describe('useDeepLink', () => {
  it('marks only the requested path pending until the link opens', async () => {
    let finish!: () => void;
    vi.mocked(openLoginLink).mockImplementation(() => new Promise<void>((resolve) => { finish = resolve; }));
    const { result } = renderHook(() => useDeepLink());

    let opening!: Promise<void>;
    act(() => {
      opening = result.current.open('/myforms/new');
    });

    expect(openLoginLink).toHaveBeenCalledWith(mint, '/myforms/new');
    expect(result.current.pendingPath).toBe('/myforms/new');

    await act(async () => {
      finish();
      await opening;
    });

    expect(result.current.pendingPath).toBeNull();
  });
});
