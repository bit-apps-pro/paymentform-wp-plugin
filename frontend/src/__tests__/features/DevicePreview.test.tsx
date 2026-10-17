import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DevicePreview } from '../../features/forms/DevicePreview';
import { PREVIEW_DEVICES } from '../../features/forms/previewScale';

describe('DevicePreview', () => {
  it('offers mobile, tablet and desktop widths', () => {
    render(<DevicePreview src="https://render.example/f1" title="Donation" loading={false} onLoad={() => undefined} />);

    for (const label of ['Mobile', 'Tablet', 'Desktop']) {
      expect(screen.getByText(label)).toBeTruthy();
    }
  });

  it('renders the form at the chosen device width', () => {
    render(<DevicePreview src="https://render.example/f1" title="Donation" loading={false} onLoad={() => undefined} />);

    fireEvent.click(screen.getByText('Desktop'));

    expect(screen.getByTitle('Donation').style.width).toBe(`${PREVIEW_DEVICES.desktop}px`);
  });

  it('shows the loading skeleton over the frame only, keeping the device switch usable', () => {
    render(<DevicePreview src="https://render.example/f1" title="Donation" loading onLoad={() => undefined} />);

    const skeleton = document.querySelector('.ant-skeleton') as HTMLElement;
    expect(skeleton.parentElement?.contains(screen.getByTitle('Donation'))).toBe(true);
    expect(skeleton.parentElement?.contains(screen.getByText('Desktop'))).toBe(false);
  });
});
