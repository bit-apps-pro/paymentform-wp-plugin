import { useMutation, useQuery } from '@tanstack/react-query';
import { request } from '../../lib/request/request';

export interface SetupDiagnoseResponse {
  // Current WP backend returns `{ next: 'ok' | 'failed', code?, message? }`.
  next?: 'ok' | 'failed';
  code?: string;
  message?: string;
  // Reserved for a future rich response shape from the backend's own
  // /api/auth/setup/diagnose endpoint (not yet implemented).
  dns?: 'ok' | 'nxdomain' | 'servfail';
  reachable?: boolean;
  tls_valid?: boolean;
  status_code?: number;
  cloudflare?: boolean;
  proof_endpoint?: 'live' | 'missing' | 'rewrite_unflushed';
}

export interface SetupResponse {
  next: 'connected' | 'redirect_connect' | 'check_inbox' | 'failed';
  has_workspace?: boolean;
  connect_url?: string;
  // Present on `check_inbox`: backend-masked email + provisioning hint so the
  // wizard can show "Sent to f***@example.com" without trusting the original
  // input shape.
  email_masked?: string;
  provisioning?: 'pending' | 'ready' | 'failed';
  code?: string;
  message?: string;
  retry_after_seconds?: number;
}

export interface VerifyOtpResponse {
  verified: boolean;
  // Present on a wrong/expended code (HTTP 200): a human-readable message the
  // WP bridge builds from the backend's `attempts_remaining`.
  error?: string;
  attempts_remaining?: number;
}

export interface ResendOtpResponse {
  ok: boolean;
  resend_after_seconds?: number;
}

/**
 * `/setup/status` is now the polling endpoint behind the deferred-token flow.
 * The WP-side controller bridges to backend `/auth/setup/finalize` and the
 * shape mirrors `SetupResponse` so the wizard can reuse the same `next:`
 * branching. `provisioning` + `email_verified` stay readable for the
 * check-inbox UI.
 */
export interface SetupStatusResponse {
  next: 'connected' | 'check_inbox' | 'failed';
  email_verified?: boolean;
  provisioning?: 'pending' | 'ready' | 'failed';
  has_workspace?: boolean;
  code?: string;
  message?: string;
}

export function useDiagnose() {
  return useMutation({
    mutationFn: async () =>
      request<SetupDiagnoseResponse>('/setup/diagnose', { method: 'POST', body: '{}' }),
  });
}

export function useSetup() {
  return useMutation({
    mutationFn: async (body: {
      email: string;
      country: string;
      locale?: string;
      email_override?: string;
    }) =>
      request<SetupResponse>('/setup', {
        method: 'POST',
        body: JSON.stringify({
          email: body.email,
          country: body.country,
          locale: body.locale || 'en_US',
          ...(body.email_override && { email_override: body.email_override }),
        }),
      }),
  });
}

export function useVerifyOtp() {
  return useMutation({
    mutationFn: async (body: { otp: string }) =>
      request<VerifyOtpResponse>('/setup/verify-otp', {
        method: 'POST',
        body: JSON.stringify(body),
      }),
  });
}

export function useResendOtp() {
  return useMutation({
    mutationFn: async () =>
      request<ResendOtpResponse>('/setup/resend-otp', { method: 'POST', body: '{}' }),
  });
}

export function useSetupStatus(enabled = false, refetchIntervalMs: number | false = false) {
  return useQuery({
    queryKey: ['setup', 'status'],
    queryFn: async () =>
      request<SetupStatusResponse>('/setup/status', { method: 'GET' }),
    refetchInterval: refetchIntervalMs,
    enabled,
    retry: 1,
    // Discard stale cache as soon as the wizard transitions out of
    // check_inbox so a re-open doesn't render last session's state.
    gcTime: 0,
    staleTime: 0,
  });
}
