import type React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';

/**
 * `edit.tsx` talks to the REST API through `@wordpress/api-fetch` (not the SPA's
 * request() helper) so the block bundle stays self-contained. These tests pin
 * the contract that move relied on: the exact REST `path`, that the plugin nonce
 * middleware is registered once, that the wp-kit envelope is unwrapped to the
 * bare payload, and that a rejection surfaces an error message.
 */

type ApiFetchOptions = { path?: string };

// apiFetch is both callable and carries `.use` / `.createNonceMiddleware`. The
// mock is a vi.fn so each test queues its own resolve/reject; the middleware
// helpers are sentinels we assert were wired up.
const apiFetchMock = vi.fn<(options: ApiFetchOptions) => Promise<unknown>>();
const useMock = vi.fn();
const nonceMiddlewareSentinel = { __nonceMiddleware: true };
const createNonceMiddlewareMock = vi.fn(() => nonceMiddlewareSentinel);

vi.mock('@wordpress/api-fetch', () => {
  const apiFetch = Object.assign(apiFetchMock, {
    use: useMock,
    createNonceMiddleware: createNonceMiddlewareMock,
  });
  return { default: apiFetch };
});

// The WP UI packages are externalized to `wp.*` globals at build time and are
// not meant to run inside the test environment (their JSON imports trip the
// transform). Stub them with the minimal surface `edit.tsx` consumes so the
// test exercises the real data path (apiFetch call, envelope unwrap, error
// mapping) rather than the heavy component tree.
vi.mock('@wordpress/block-editor', () => ({
  useBlockProps: () => ({ className: 'paymentform-embed-edit' }),
}));

vi.mock('@wordpress/i18n', () => ({
  __: (text: string) => text,
}));

vi.mock('@wordpress/element', async () => {
  const react = await import('react');
  return { useState: react.useState, useEffect: react.useEffect };
});

vi.mock('@wordpress/components', () => ({
  SelectControl: ({ options }: { options: { label: string; value: string }[] }) => (
    <select>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  ),
  Placeholder: ({ instructions }: { instructions?: React.ReactNode }) => <div>{instructions}</div>,
  Spinner: () => <div role="status" aria-label="loading" />,
  Button: ({ children }: { children: React.ReactNode }) => <button>{children}</button>,
}));

async function renderEdit() {
  // Re-import per test so the module-scope `nonceMiddlewareRegistered` guard is
  // reset; otherwise the "registers once" assertions would leak across cases.
  const { Edit } = await import('./edit');
  return render(<Edit attributes={{ formId: '' }} setAttributes={vi.fn()} />);
}

describe('form-embed Edit', () => {
  beforeEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
  });

  it('requests the paymentform/v1/forms REST path', async () => {
    apiFetchMock.mockResolvedValueOnce([]);

    await renderEdit();

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalled());
    expect(apiFetchMock).toHaveBeenCalledWith({ path: 'paymentform/v1/forms' });
  });

  it('registers the plugin nonce middleware from window.paymentformAdmin.nonce', async () => {
    apiFetchMock.mockResolvedValueOnce([]);

    await renderEdit();

    await waitFor(() => expect(createNonceMiddlewareMock).toHaveBeenCalledWith('test-nonce'));
    expect(useMock).toHaveBeenCalledWith(nonceMiddlewareSentinel);
  });

  it('unwraps the wp-kit envelope to the bare forms array', async () => {
    apiFetchMock.mockResolvedValueOnce({
      status: 'success',
      code: 'SUCCESS',
      data: [{ id: 'form_1', name: 'Donate' }],
    });

    await renderEdit();

    // The select renders an option per form once the unwrapped array lands.
    await waitFor(() => expect(screen.getByRole('option', { name: 'Donate' })).toBeInTheDocument());
  });

  it('renders a non-enveloped array body as-is', async () => {
    apiFetchMock.mockResolvedValueOnce([{ id: 'form_2', name: 'Subscribe' }]);

    await renderEdit();

    await waitFor(() =>
      expect(screen.getByRole('option', { name: 'Subscribe' })).toBeInTheDocument(),
    );
  });

  it('shows the rejected error body message', async () => {
    apiFetchMock.mockRejectedValueOnce({ code: 'rest_forbidden', message: 'You are not allowed.' });

    await renderEdit();

    await waitFor(() => expect(screen.getByText('You are not allowed.')).toBeInTheDocument());
  });

  it('falls back to a generic message when the rejection carries no message', async () => {
    apiFetchMock.mockRejectedValueOnce({});

    await renderEdit();

    await waitFor(() => expect(screen.getByText('Failed to load forms')).toBeInTheDocument());
  });
});
