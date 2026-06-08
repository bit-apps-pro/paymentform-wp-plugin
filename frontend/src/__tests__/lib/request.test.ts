import { describe, it, expect, beforeEach, vi } from 'vitest';
import { request } from '../../lib/request/request';

describe('request utility', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('attaches X-WP-Nonce header', async () => {
    const mockFetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({ success: true }),
    });
    (globalThis as any).fetch = mockFetch;

    await request('/test');

    expect(mockFetch).toHaveBeenCalled();
    const callArgs = (mockFetch.mock.calls[0] || []) as any[];
    expect(callArgs[0]).toContain('/test');
    expect(callArgs[1]?.headers.get('X-WP-Nonce')).toBe('test-nonce');
  });

  it('parses error responses', async () => {
    const mockFetch = vi.fn().mockResolvedValueOnce({
      ok: false,
      status: 404,
      statusText: 'Not Found',
      json: async () => ({ code: 'not_found', message: 'Resource not found' }),
    });
    (globalThis as any).fetch = mockFetch;

    try {
      await request('/missing');
      expect.fail('Should have thrown error');
    } catch (error: any) {
      expect(error.code).toBe('not_found');
      expect(error.message).toBe('Resource not found');
      expect(error.status).toBe(404);
    }
  });

  it('returns parsed JSON on success', async () => {
    const mockFetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({ id: '123', name: 'Test' }),
    });
    (globalThis as any).fetch = mockFetch;

    const result = await request('/test');

    expect(result).toEqual({ id: '123', name: 'Test' });
  });
});
