import type { ApiError } from './types'

/**
 * Shape of `window.paymentformAdmin` injected by
 * `PaymentForm\Providers\PluginServiceProvider::enqueueAdminAssets`.
 * Declared inline (not via the global namespace) so this module owns the
 * narrowing — every other consumer should pull it from a typed helper
 * rather than touching `window` directly.
 */
type PaymentformAdminConfig = {
  restUrl: string
  nonce: string
}

declare global {
  // eslint-disable-next-line no-var
  var paymentformAdmin: PaymentformAdminConfig | undefined
}

type ErrorBody = {
  error_code?: unknown
  message?: unknown
}

interface FetchInit {
  method?: string
  headers?: Record<string, string>
  body?: string
  credentials?: 'same-origin' | 'include' | 'omit'
}

export async function request<T>(path: string, init: FetchInit = {}): Promise<T> {
  const config: PaymentformAdminConfig | undefined = (window as unknown as { paymentformAdmin?: PaymentformAdminConfig }).paymentformAdmin

  if (!config) {
    throw new Error('paymentform: window.paymentformAdmin not initialized')
  }

  const { restUrl, nonce } = config
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
    const errorCode = typeof errorBody.error_code === 'string' ? errorBody.error_code : `HTTP_${res.status}`
    const message = typeof errorBody.message === 'string' ? errorBody.message : res.statusText

    const err: ApiError = Object.assign(new Error(message), {
      errorCode,
      message,
      status: res.status,
      details: errorBody,
    })
    throw err
  }

  return body as T
}
