import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { EmbedDrawer } from '../../features/forms/EmbedDrawer';
import type { Form } from '../../features/forms/useFormsList';

vi.mock('../../features/forms/embedUrl', () => ({
  getEmbedUrl: (id: string) => `https://render.example/${id}`,
  getShortcode: (id: string) => `[paymentform id="${id}"]`,
  getGutenbergBlock: (id: string) => `<!-- wp:paymentform/form {"formId":"${id}"} /-->`,
}));

const FORM: Form = {
  id: 'f1',
  name: 'Donation',
  slug: 'donation',
  status: 'enabled',
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
};

describe('EmbedDrawer', () => {
  it('offers device sizes on the preview tab', () => {
    render(<EmbedDrawer form={FORM} onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Preview' }));

    expect(screen.getByText('Desktop')).toBeTruthy();
  });
});
