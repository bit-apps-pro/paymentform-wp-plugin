import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App as AntApp } from 'antd';
import { CopyEmbedButton } from '../../features/forms/CopyEmbedButton';

const mocks = vi.hoisted(() => ({
  copyText: vi.fn(),
  embedUrl: 'https://render.example/f1' as string | null,
}));
vi.mock('../../lib/clipboard', () => ({ copyText: mocks.copyText }));
vi.mock('../../features/forms/embedUrl', () => ({
  getEmbedUrl: () => mocks.embedUrl,
  getShortcode: (id: string) => `[paymentform id="${id}"]`,
  getGutenbergBlock: (id: string) => `<!-- wp:paymentform/form {"formId":"${id}"} /-->`,
}));

/** Renders the split button for form f1 inside antd's App (for message toasts). */
function renderButton(): void {
  render(
    <AntApp>
      <CopyEmbedButton formId="f1" />
    </AntApp>,
  );
}

/** Opens the embed options menu and returns the menu item with the given label. */
async function openMenuItem(label: RegExp): Promise<HTMLElement> {
  fireEvent.click(screen.getByRole('button', { name: 'More embed options' }));
  return screen.findByRole('menuitem', { name: label });
}

describe('CopyEmbedButton', () => {
  beforeEach(() => {
    mocks.copyText.mockReset().mockResolvedValue(undefined);
    mocks.embedUrl = 'https://render.example/f1';
  });

  it('copies the shortcode in one click', async () => {
    renderButton();

    fireEvent.click(screen.getByRole('button', { name: /Copy shortcode/ }));

    await waitFor(() => expect(mocks.copyText).toHaveBeenCalledWith('[paymentform id="f1"]'));
    expect(await screen.findByText('Shortcode copied')).toBeInTheDocument();
  });

  it('copies the block markup from the menu', async () => {
    renderButton();

    fireEvent.click(await openMenuItem(/Block markup/));

    await waitFor(() =>
      expect(mocks.copyText).toHaveBeenCalledWith('<!-- wp:paymentform/form {"formId":"f1"} /-->'),
    );
  });

  it('disables the iframe URL item until the site is connected', async () => {
    mocks.embedUrl = null;
    renderButton();

    const item = await openMenuItem(/Iframe URL/);
    fireEvent.click(item);

    expect(item).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByText(/Connect this site to PaymentForm/)).toBeInTheDocument();
    expect(mocks.copyText).not.toHaveBeenCalled();
  });

  it('reports a failed copy', async () => {
    mocks.copyText.mockRejectedValue(new Error('denied'));
    renderButton();

    fireEvent.click(screen.getByRole('button', { name: /Copy shortcode/ }));

    expect(await screen.findByText('Copy failed')).toBeInTheDocument();
  });
});
