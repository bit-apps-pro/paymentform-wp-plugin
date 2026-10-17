import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { App as AntApp } from 'antd';
import { ErrorFallback } from '../../components/ErrorFallback';

describe('ErrorFallback', () => {
  it('shows a copy icon on the diagnostic button', () => {
    render(
      <AntApp>
        <ErrorFallback error={new Error('boom')} />
      </AntApp>,
    );

    const button = screen.getByRole('button', { name: /Copy diagnostic info/ });
    expect(button.querySelector('svg')).not.toBeNull();
  });
});
