import React, { Component, type ReactNode } from 'react';

type FallbackFn = (props: { error: Error | null; reset: () => void }) => ReactNode;

interface Props {
  children: ReactNode;
  fallback?: ReactNode | FallbackFn;
}

interface State {
  hasError: boolean;
  error: Error | null;
  componentStack: string | null;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null, componentStack: null };
  }

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { hasError: true, error };
  }

  override componentDidCatch(error: Error, info: React.ErrorInfo) {
    this.setState({ componentStack: info.componentStack ?? null });
    if (typeof window !== 'undefined' && (window as { __PAYMENTFORM_DEBUG__?: boolean }).__PAYMENTFORM_DEBUG__) {
      console.error('[paymentform] render error:', error);
      console.error(info.componentStack);
    }
  }

  private reset = () => {
    this.setState({ hasError: false, error: null, componentStack: null });
  };

  override render() {
    if (this.state.hasError) {
      if (typeof this.props.fallback === 'function') {
        return this.props.fallback({ error: this.state.error, reset: this.reset });
      }
      return this.props.fallback ?? <div>Something went wrong</div>;
    }
    return this.props.children;
  }
}
