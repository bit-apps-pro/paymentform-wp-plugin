import type { ApiError } from './types';

interface FetchInit {
  method?: string;
  headers?: Record<string, string>;
  body?: string;
  credentials?: 'same-origin' | 'include' | 'omit';
}

export async function request<T>(path: string, init: FetchInit = {}): Promise<T> {
  const config = (window as any).paymentformAdmin;
  if (!config) {
    throw new Error('paymentform: window.paymentformAdmin not initialized');
  }

  const { restUrl, nonce } = config;
  const url = restUrl + path.replace(/^\//, '');
  const headers = new Headers(init.headers);

  headers.set('X-WP-Nonce', nonce);
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const res = await fetch(url, { ...init, headers, credentials: 'same-origin' });

  let body: unknown;
  try {
    body = await res.json();
  } catch {
    body = {};
  }

  if (!res.ok) {
    const errorBody = body as any;
    const err: ApiError = Object.assign(new Error(), {
      code: errorBody?.code ?? `http_${res.status}`,
      message: errorBody?.message ?? res.statusText,
      status: res.status,
      details: errorBody,
    });
    throw err;
  }

  return body as T;
}
