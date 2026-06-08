import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { ErrorBoundary } from '../../components/ErrorBoundary';

const ThrowComponent = () => {
  throw new Error('Test error');
};

describe('ErrorBoundary', () => {
  it('catches errors in child components', () => {
    // Suppress console.error during test
    const consoleSpy = console.error;
    console.error = () => {};

    const { container } = render(
      <ErrorBoundary fallback={<div>Error caught</div>}>
        <ThrowComponent />
      </ErrorBoundary>,
    );

    expect(container.textContent).toContain('Error caught');

    console.error = consoleSpy;
  });

  it('renders children when no error occurs', () => {
    const { getByText } = render(
      <ErrorBoundary>
        <div>Success</div>
      </ErrorBoundary>,
    );

    expect(getByText('Success')).toBeInTheDocument();
  });
});
