import { useMutation } from '@tanstack/react-query';
import { request } from '../../lib/request/request';

interface LoginLinkResponse {
  url: string;
}

/**
 * Mints a one-time dashboard SSO login link via the plugin REST route
 * (`POST /paymentform/v1/login-link`, nonce + cap protected). Returns the full
 * single-origin dashboard URL the caller opens in a new tab.
 *
 * The mutation deliberately carries NO onSuccess/onError side effects: callers
 * (`openLoginLink`) await `mutateAsync` so a mint failure rejects and they can
 * fall back to `/signin` (spec §L3). A 401 from a stale token is resolved by
 * the backend ApiClient's refresh-once-then-retry before it ever reaches here.
 *
 * @param redirect optional dashboard path; defaults server-side to `/myforms`.
 */
export function useLoginLink() {
  return useMutation<LoginLinkResponse, Error, string | undefined>({
    mutationFn: (redirect?: string) =>
      request<LoginLinkResponse>('/login-link', {
        method: 'POST',
        body: JSON.stringify(redirect ? { redirect } : {}),
      }),
  });
}
