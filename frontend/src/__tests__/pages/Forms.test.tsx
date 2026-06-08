import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from 'antd';
import { Forms } from '../../pages/Forms';
import { queryClient } from '../../lib/queryClient';
import { antdTheme } from '../../theme/antdTheme';

describe('Forms page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', () => {
    (globalThis as any).fetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({ data: [] }),
    });

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
    (globalThis as any).fetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      json: async () => ({ data: [] }),
    });

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
