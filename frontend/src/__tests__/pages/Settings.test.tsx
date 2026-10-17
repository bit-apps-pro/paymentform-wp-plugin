import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { App as AntApp } from 'antd';
import { Settings } from '../../pages/Settings';
import type { ConnectionStatus } from '../../features/auth/useConnection';

const connection = vi.hoisted(() => ({ data: undefined as ConnectionStatus | undefined }));
vi.mock('../../features/auth/useConnection', () => ({
  useConnection: () => ({ data: connection.data, isLoading: false }),
}));

/** Renders the settings page inside the antd app context it expects. */
function renderSettings(): void {
  render(
    <AntApp>
      <Settings />
    </AntApp>,
  );
}

describe('Settings', () => {
  beforeEach(() => {
    connection.data = { connected: false };
  });

  it('shows a copy icon on the diagnostic button', () => {
    renderSettings();

    const button = screen.getByRole('button', { name: /Copy diagnostic info/ });
    expect(button.querySelector('svg')).not.toBeNull();
  });

  it('shows the connected workspace and account', () => {
    connection.data = {
      connected: true,
      user: { id: 'u1', email: 'owner@example.test', name: 'Owner' },
      tenant: { id: 't1', name: 'Acme Workspace' },
    };
    renderSettings();

    const row = screen.getByText('Connected account').closest('tr') as HTMLElement;
    expect(within(row).getByText(/Acme Workspace/)).toBeTruthy();
    expect(within(row).getByText(/owner@example\.test/)).toBeTruthy();
  });

  it('omits the connected account row when disconnected', () => {
    renderSettings();

    expect(screen.queryByText('Connected account')).toBeNull();
  });

  it('truncates a long site URL instead of wrapping it mid-domain', () => {
    renderSettings();

    const row = screen.getByText('Site URL').closest('tr') as HTMLElement;
    expect(row.querySelector('.ant-typography-ellipsis')).not.toBeNull();
  });
});
