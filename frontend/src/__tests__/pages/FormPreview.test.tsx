import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Outlet, Route, Routes } from 'react-router-dom';
import type { ShellOutletContext } from '../../components/HeaderActions';
import { FormPreview } from '../../pages/FormPreview';

const mocks = vi.hoisted(() => ({ embedUrl: 'https://render.example/f1' as string | null }));
vi.mock('../../features/forms/embedUrl', () => ({ getEmbedUrl: () => mocks.embedUrl }));
vi.mock('../../features/forms/useFormsList', () => ({
  useFormsList: () => ({ data: [{ id: 'f1', name: 'Donation' }] }),
}));

/** Minimal AppShell stand-in exposing the header actions slot to its routes. */
function ShellStub() {
  const [slot, setSlot] = useState<HTMLDivElement | null>(null);
  const context: ShellOutletContext = { headerActionsSlot: slot };
  return (
    <>
      <div data-testid="header-actions" ref={setSlot} />
      <Outlet context={context} />
    </>
  );
}

/** Renders the preview route for the given form id inside the shell stub. */
function renderPreview(formId = 'f1'): void {
  render(
    <MemoryRouter initialEntries={[`/forms/${formId}/preview`]}>
      <Routes>
        <Route element={<ShellStub />}>
          <Route path="forms/:formId/preview" element={<FormPreview />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe('FormPreview page', () => {
  beforeEach(() => {
    mocks.embedUrl = 'https://render.example/f1';
  });

  it('frames the form and puts the device switch in the shell header', () => {
    renderPreview();

    expect(screen.getByTitle('Donation')).toHaveAttribute('src', 'https://render.example/f1');
    const header = screen.getByTestId('header-actions');
    for (const label of ['Mobile', 'Tablet', 'Desktop']) {
      expect(header).toHaveTextContent(label);
    }
  });

  it('falls back to a generic frame title for an unknown form', () => {
    renderPreview('missing');

    expect(screen.getByTitle('Preview')).toBeInTheDocument();
  });

  it('explains how to enable previews when the site is not connected', () => {
    mocks.embedUrl = null;
    renderPreview();

    expect(screen.getByText(/Connect this site to PaymentForm to preview forms here/)).toBeInTheDocument();
    expect(document.querySelector('iframe')).toBeNull();
    expect(screen.getByTestId('header-actions')).toBeEmptyDOMElement();
  });
});
