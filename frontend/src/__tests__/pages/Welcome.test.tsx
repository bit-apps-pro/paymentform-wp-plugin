import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, queryAllByRole, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from 'antd';
import { Welcome } from '../../pages/Welcome';
import { queryClient } from '../../lib/queryClient';
import { antdTheme } from '../../theme/antdTheme';

describe('Welcome page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders with Connect button', () => {
    const { container } = render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <ConfigProvider theme={antdTheme}>
            <Welcome />
          </ConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>,
    );

    const buttons = queryAllByRole(container, 'button');
    expect(buttons.length).toBeGreaterThan(0);
    expect(container.textContent).toMatch(/Connect|paymentform/i);
  });

  it('renders without crashing', () => {
    const { container } = render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <ConfigProvider theme={antdTheme}>
            <Welcome />
          </ConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>,
    );

    expect(container).toBeInTheDocument();
  });

  it('renders its call-to-action buttons at the default control size', () => {
    render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <ConfigProvider theme={antdTheme}>
            <Welcome />
          </ConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>,
    );

    for (const name of [/Connect existing account/, /Create new account/]) {
      expect(screen.getByRole('button', { name }).className).not.toContain('ant-btn-lg');
    }
  });
});
