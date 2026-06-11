import { describe, it, expect, beforeEach, vi } from 'vitest';
import { request } from '../../lib/request/request';
import type { ApiError } from '../../lib/request/types';

type FetchStub = {
  ok: boolean;
  status?: number;
  statusText?: string;
  body: unknown;
};

function stubFetch({ ok, status, statusText = '', body }: FetchStub) {
  const mockFetch = vi.fn().mockResolvedValueOnce({
    ok,
    status: status ?? (ok ? 200 : 500),
    statusText,
    json: async () => body,
  });
  (globalThis as any).fetch = mockFetch;
  return mockFetch;
}

async function captureError(path: string): Promise<ApiError> {
  try {
    await request(path);
  } catch (error) {
    return error as ApiError;
  }
  throw new Error('Expected request to throw');
}

describe('request utility', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('attaches X-WP-Nonce header', async () => {
    const mockFetch = stubFetch({ ok: true, body: { success: true } });

    await request('/test');

    expect(mockFetch).toHaveBeenCalled();
    const callArgs = (mockFetch.mock.calls[0] || []) as any[];
    expect(callArgs[0]).toContain('/test');
    expect(callArgs[1]?.headers.get('X-WP-Nonce')).toBe('test-nonce');
  });

  it('unwraps the wp-kit success envelope to the bare payload', async () => {
    stubFetch({
      ok: true,
      body: { status: 'success', code: 'SUCCESS', data: { connected: true, tenant: 'acme' } },
    });

    const result = await request('/status');

    expect(result).toEqual({ connected: true, tenant: 'acme' });
  });

  it('unwraps an enveloped array payload (forms list)', async () => {
    stubFetch({
      ok: true,
      body: { status: 'success', code: 'SUCCESS', data: [{ id: '1', name: 'Donate' }] },
    });

    const result = await request('/forms');

    expect(result).toEqual([{ id: '1', name: 'Donate' }]);
  });

  it('keeps inner payload keys (including code) intact after unwrap', async () => {
    stubFetch({
      ok: true,
      body: {
        status: 'success',
        code: 'SUCCESS',
        data: { next: 'failed', code: 'bad_request', message: 'Invalid email address.' },
      },
    });

    const result = await request('/setup');

    expect(result).toEqual({ next: 'failed', code: 'bad_request', message: 'Invalid email address.' });
  });

  it('returns non-enveloped bodies as-is (fallback path)', async () => {
    stubFetch({ ok: true, body: { id: '123', name: 'Test' } });

    const result = await request('/test');

    expect(result).toEqual({ id: '123', name: 'Test' });
  });

  it('parses error responses with error_code', async () => {
    stubFetch({
      ok: false,
      status: 404,
      statusText: 'Not Found',
      body: { error_code: 'NOT_FOUND', message: 'Resource not found' },
    });

    const error = await captureError('/missing');

    expect(error.errorCode).toBe('NOT_FOUND');
    expect(error.message).toBe('Resource not found');
    expect(error.status).toBe(404);
  });

  it('maps the wp-kit envelope code to errorCode when error_code is absent', async () => {
    stubFetch({
      ok: false,
      status: 403,
      statusText: 'Forbidden',
      body: { status: 'error', code: 'NOT_AUTHORIZED', message: 'You are not allowed to do this.', data: [] },
    });

    const error = await captureError('/status');

    expect(error.errorCode).toBe('NOT_AUTHORIZED');
    expect(error.message).toBe('You are not allowed to do this.');
    expect(error.status).toBe(403);
  });

  it('surfaces setup error codes via the envelope code fallback', async () => {
    stubFetch({
      ok: false,
      status: 503,
      statusText: 'Service Unavailable',
      body: {
        status: 'error',
        code: 'service_unavailable',
        message: 'Service temporarily unavailable.',
        data: { next: 'failed', code: 'service_unavailable', retry_after_seconds: 60 },
      },
    });

    const error = await captureError('/setup');

    expect(error.errorCode).toBe('service_unavailable');
    expect(error.status).toBe(503);
  });

  it('prefers error_code over the envelope code when both are present', async () => {
    stubFetch({
      ok: false,
      status: 401,
      statusText: 'Unauthorized',
      body: { error_code: 'DISCONNECTED', code: 'ERROR', message: 'Not connected' },
    });

    const error = await captureError('/forms');

    expect(error.errorCode).toBe('DISCONNECTED');
    expect(error.status).toBe(401);
  });

  it('falls back to HTTP_<status> when no error_code or code is present', async () => {
    stubFetch({ ok: false, status: 502, statusText: 'Bad Gateway', body: {} });

    const error = await captureError('/test');

    expect(error.errorCode).toBe('HTTP_502');
    expect(error.message).toBe('Bad Gateway');
    expect(error.status).toBe(502);
  });
});
