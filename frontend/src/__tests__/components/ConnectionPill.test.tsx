import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConnectionPill } from '../../components/ConnectionPill';
import type { ConnectionStatus } from '../../features/auth/useConnection';

const connection = vi.hoisted(() => ({ data: undefined as ConnectionStatus | undefined }));
vi.mock('../../features/auth/useConnection', () => ({
  useConnection: () => ({ data: connection.data, isLoading: false }),
}));

describe('ConnectionPill', () => {
  beforeEach(() => {
    connection.data = { connected: false };
  });

  it('names the connected workspace', () => {
    connection.data = {
      connected: true,
      user: { id: 'u1', email: 'owner@example.test', name: 'Owner' },
      tenant: { id: 't1', name: 'Acme Workspace' },
    };
    render(<ConnectionPill />);

    expect(screen.getByText('Connected · Acme Workspace')).toBeTruthy();
  });

  it('reveals the account email on hover', async () => {
    connection.data = {
      connected: true,
      user: { id: 'u1', email: 'owner@example.test', name: 'Owner' },
      tenant: { id: 't1', name: 'Acme Workspace' },
    };
    render(<ConnectionPill />);

    fireEvent.mouseEnter(screen.getByText('Connected · Acme Workspace'));

    expect(await screen.findByText(/owner@example\.test/)).toBeTruthy();
  });

  it('reads Disconnected without a workspace name', () => {
    render(<ConnectionPill />);

    expect(screen.getByText('Disconnected')).toBeTruthy();
  });
});
