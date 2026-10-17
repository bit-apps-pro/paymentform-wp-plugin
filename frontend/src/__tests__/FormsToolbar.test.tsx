import { describe, it, expect, vi } from 'vitest';
import type { ReactNode } from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { ConfigProvider, App as AntApp } from 'antd';
import { MemoryRouter } from 'react-router-dom';
import { FormsToolbar } from '../features/forms/FormsToolbar';

function Wrap({ children }: { children: ReactNode }) {
  return (
    <MemoryRouter>
      <ConfigProvider>
        <AntApp>{children}</AntApp>
      </ConfigProvider>
    </MemoryRouter>
  );
}

describe('FormsToolbar', () => {
  it('emits onChange when search input changes', () => {
    const onChange = vi.fn();
    const onRefresh = vi.fn();
    render(
      <Wrap>
        <FormsToolbar
          value={{ q: '', status: 'all' }}
          onChange={onChange}
          onRefresh={onRefresh}
        />
      </Wrap>
    );
    const search = screen.getByPlaceholderText(/search forms/i) as HTMLInputElement;
    fireEvent.change(search, { target: { value: 'donation' } });
    expect(onChange).toHaveBeenCalledWith({ q: 'donation', status: 'all' });
  });

  it('emits onChange when status select changes', () => {
    const onChange = vi.fn();
    const onRefresh = vi.fn();
    render(
      <Wrap>
        <FormsToolbar
          value={{ q: '', status: 'all' }}
          onChange={onChange}
          onRefresh={onRefresh}
        />
      </Wrap>
    );
    const select = screen.getByRole('combobox');
    fireEvent.mouseDown(select);
    const enabled = screen.getByText('Enabled');
    fireEvent.click(enabled);
    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({ status: 'enabled' })
    );
  });

  it('fires onRefresh when refresh button clicked', () => {
    const onChange = vi.fn();
    const onRefresh = vi.fn();
    render(
      <Wrap>
        <FormsToolbar
          value={{ q: '', status: 'all' }}
          onChange={onChange}
          onRefresh={onRefresh}
        />
      </Wrap>
    );
    fireEvent.click(screen.getByRole('button', { name: /refresh/i }));
    expect(onRefresh).toHaveBeenCalled();
  });

  it('offers no table density toggle', () => {
    render(
      <Wrap>
        <FormsToolbar value={{ q: '', status: 'all' }} onChange={vi.fn()} onRefresh={vi.fn()} />
      </Wrap>
    );
    expect(screen.queryByText('Comfortable')).toBeNull();
    expect(screen.queryByText('Compact')).toBeNull();
  });
});

// suppress react act() warning noise
void act;
