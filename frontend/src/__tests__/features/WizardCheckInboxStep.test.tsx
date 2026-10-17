import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { App as AntApp } from 'antd';
import { WizardCheckInboxStep } from '../../features/setup/WizardCheckInboxStep';
import type { WizardState } from '../../features/setup/state';

vi.mock('../../features/setup/queries', () => ({
  useVerifyOtp: () => ({ mutate: vi.fn(), isPending: false }),
  useResendOtp: () => ({ mutate: vi.fn(), isPending: false }),
  useSetupStatus: () => ({
    refetch: vi.fn().mockResolvedValue({ data: { next: 'pending', email_verified: false } }),
    data: undefined,
    isFetching: false,
  }),
}));

const TOAST_TOP_PX = 99;

describe('WizardCheckInboxStep', () => {
  it('shows its status toast through the app-level message config', async () => {
    render(
      <AntApp message={{ top: TOAST_TOP_PX }}>
        <WizardCheckInboxStep state={{ email: 'kai@example.test' } as WizardState} dispatch={vi.fn()} />
      </AntApp>,
    );

    fireEvent.click(screen.getByRole('button', { name: /I clicked the link in my email/ }));

    const toast = await screen.findByText(/Email not yet verified/);
    expect((toast.closest('.ant-message') as HTMLElement).style.getPropertyValue('--notification-top')).toBe(`${TOAST_TOP_PX}px`);
  });
});
