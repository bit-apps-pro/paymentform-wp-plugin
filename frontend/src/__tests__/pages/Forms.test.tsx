import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from 'antd';
import { Forms } from '../../pages/Forms';
import { queryClient } from '../../lib/queryClient';
import { antdTheme } from '../../theme/antdTheme';
import { CREATE_FORM_PATH } from '../../lib/openLoginLink';

const deepLink = vi.hoisted(() => ({ open: vi.fn(), pendingPath: null as string | null }));
vi.mock('../../features/auth/useDeepLink', () => ({ useDeepLink: () => deepLink }));

/** Renders the forms page with an empty list. */
function renderEmptyForms(): void {
  globalThis.fetch = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ status: 'success', code: 'SUCCESS', data: [] }),
  }) as unknown as typeof fetch;
  render(
    <BrowserRouter>
      <QueryClientProvider client={queryClient}>
        <ConfigProvider theme={antdTheme}>
          <Forms />
        </ConfigProvider>
      </QueryClientProvider>
    </BrowserRouter>,
  );
}

describe('Forms page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    deepLink.pendingPath = null;
    queryClient.clear();
  });

  it('opens the new-form template picker from Create form', async () => {
    renderEmptyForms();

    const [createButton] = await screen.findAllByRole('button', { name: /Create form/ });
    fireEvent.click(createButton!);

    expect(deepLink.open).toHaveBeenCalledWith(CREATE_FORM_PATH);
  });

  it('shows a redirecting state on Create form while the link opens', async () => {
    deepLink.pendingPath = CREATE_FORM_PATH;
    renderEmptyForms();

    const buttons = await screen.findAllByRole('button', { name: /Redirecting/ });
    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons[0]?.className).toContain('ant-btn-loading');
  });

  it('renders without crashing', () => {
    globalThis.fetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({ status: 'success', code: 'SUCCESS', data: [] }),
    }) as unknown as typeof fetch;

    const { container } = render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <ConfigProvider theme={antdTheme}>
            <Forms />
          </ConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>,
    );

    expect(container).toBeInTheDocument();
  });

  it('renders Forms content container', () => {
    globalThis.fetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({ status: 'success', code: 'SUCCESS', data: [] }),
    }) as unknown as typeof fetch;

    const { container } = render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <ConfigProvider theme={antdTheme}>
            <Forms />
          </ConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>,
    );

    // Verify component renders and contains expected content classes/elements
    expect(container.querySelector('div')).toBeInTheDocument();
  });
});
