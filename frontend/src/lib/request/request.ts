import type { ApiError } from './types'
import { wpModuleData } from '../../utils/wpData'

type ErrorBody = {
  error_code?: unknown
  code?: unknown
  message?: unknown
}

interface FetchInit {
  method?: string
  headers?: Record<string, string>
  body?: string
  credentials?: 'same-origin' | 'include' | 'omit'
}

export async function request<T>(path: string, init: FetchInit = {}): Promise<T> {
  if (!wpModuleData.restUrl) {
    throw new Error('paymentform: admin config not initialized')
  }

  const { restUrl, nonce } = wpModuleData
  const url = restUrl + path.replace(/^\//, '')
  const headers = new Headers(init.headers)

  headers.set('X-WP-Nonce', nonce)
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const res = await fetch(url, { ...init, headers, credentials: 'same-origin' })

  let body: unknown
  try {
    body = await res.json()
  } catch {
    body = {}
  }

  if (!res.ok) {
    const errorBody = (body ?? {}) as ErrorBody
    // `error_code` first (proxied-backend errors via ApiClient), then the
    // wp-kit envelope `code`, then a synthetic HTTP fallback.
    const errorCode =
      typeof errorBody.error_code === 'string' ? errorBody.error_code
      : typeof errorBody.code === 'string' ? errorBody.code
      : `HTTP_${res.status}`
    const message = typeof errorBody.message === 'string' ? errorBody.message : res.statusText

    const err: ApiError = Object.assign(new Error(message), {
      errorCode,
      message,
      status: res.status,
      details: errorBody,
    })
    throw err
  }

  // wp-kit wraps payloads as { status, code, data, message? }. Unwrap to the
  // bare payload so call sites keep their existing response types. Requiring
  // all three envelope keys (status is always 'success' | 'error') avoids
  // wrongly unwrapping a non-enveloped payload that happens to carry data+code.
  if (body && typeof body === 'object' && 'status' in body && 'code' in body && 'data' in body) {
    return (body as { data: T }).data
  }
  return body as T
}
