import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DevicePreview, DeviceSwitch } from '../../features/forms/DevicePreview';
import { PREVIEW_DEVICES } from '../../features/forms/previewScale';

describe('DeviceSwitch', () => {
  it('offers mobile, tablet and desktop widths', () => {
    const onChange = vi.fn();
    render(<DeviceSwitch value="mobile" onChange={onChange} />);

    fireEvent.click(screen.getByText('Desktop'));

    expect(screen.getByText('Tablet')).toBeTruthy();
    expect(onChange).toHaveBeenCalledWith('desktop');
  });
});

describe('DevicePreview', () => {
  it('renders the form at the chosen device width', () => {
    render(
      <DevicePreview src="https://render.example/f1" title="Donation" device="desktop" loading={false} onLoad={() => undefined} />,
    );

    expect(screen.getByTitle('Donation').style.width).toBe(`${PREVIEW_DEVICES.desktop}px`);
  });

  it('shows the loading skeleton over the frame while it loads', () => {
    render(<DevicePreview src="https://render.example/f1" title="Donation" device="mobile" loading onLoad={() => undefined} />);

    const skeleton = document.querySelector('.ant-skeleton') as HTMLElement;
    expect(skeleton.parentElement?.contains(screen.getByTitle('Donation'))).toBe(true);
  });
});
