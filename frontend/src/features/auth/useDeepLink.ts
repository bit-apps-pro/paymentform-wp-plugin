import { useCallback, useState } from 'react';
import { useLoginLink } from './useLoginLink';
import { openLoginLink } from '../../lib/openLoginLink';

/** Opens dashboard deep links via SSO and reports which path is still opening, for per-button loading state. */
export function useDeepLink(): { open: (path?: string) => Promise<void>; pendingPath: string | null } {
  const { mutateAsync: mint } = useLoginLink();
  const [pendingPath, setPendingPath] = useState<string | null>(null);

  const open = useCallback(
    async (path?: string) => {
      setPendingPath(path ?? '');
      try {
        await openLoginLink(mint, path);
      } finally {
        setPendingPath(null);
      }
    },
    [mint],
  );

  return { open, pendingPath };
}
