import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { WizardSuccessStep } from '../../features/setup/WizardSuccessStep';
import type { WizardState } from '../../features/setup/state';

vi.mock('../../features/auth/useLoginLink', () => ({
  useLoginLink: () => ({ mutateAsync: vi.fn() }),
}));

describe('WizardSuccessStep', () => {
  it('renders its primary button at the default control size', () => {
    render(<WizardSuccessStep state={{ hasWorkspace: false } as WizardState} onClose={vi.fn()} />);

    expect(screen.getByRole('button').className).not.toContain('ant-btn-lg');
  });
});
