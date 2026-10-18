import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AppBreadcrumb } from '../../components/AppBreadcrumb';

vi.mock('../../features/forms/useFormsList', () => ({
  useFormsList: () => ({ data: [{ id: 'f1', name: 'Donation' }] }),
}));

/** Renders the breadcrumb at the given router path, plus a forms-list stand-in to navigate back to. */
function renderAt(path: string): void {
  render(
    <MemoryRouter initialEntries={[path]}>
      <AppBreadcrumb />
      <Routes>
        <Route path="forms" element={<p>Forms list</p>} />
        <Route path="*" element={null} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('AppBreadcrumb', () => {
  it('shows the section for a top-level page', () => {
    renderAt('/forms');

    expect(screen.getAllByText('Forms').length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Back to forms' })).toBeNull();
  });

  it('names the previewed form and links back to Forms', () => {
    renderAt('/forms/f1/preview');

    expect(screen.getByRole('link', { name: 'Forms' })).toHaveAttribute('href', '/forms');
    expect(screen.getByText('Donation')).toBeInTheDocument();
  });

  it('falls back to "Preview" for an unknown form', () => {
    renderAt('/forms/missing/preview');

    expect(screen.getByText('Preview')).toBeInTheDocument();
  });

  it('navigates back to the forms list from a preview', () => {
    renderAt('/forms/f1/preview');

    fireEvent.click(screen.getByRole('button', { name: 'Back to forms' }));

    expect(screen.getByText('Forms list')).toBeInTheDocument();
  });
});
