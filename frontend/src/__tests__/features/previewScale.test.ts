import { describe, it, expect } from 'vitest';
import { PREVIEW_DEVICES, previewScale } from '../../features/forms/previewScale';

describe('previewScale', () => {
  it('shrinks a wide device to fit the container', () => {
    expect(previewScale(480, PREVIEW_DEVICES.desktop)).toBe(0.375);
  });

  it('never upscales a device narrower than the container', () => {
    expect(previewScale(480, PREVIEW_DEVICES.mobile)).toBe(1);
  });

  it('falls back to 1 for an unmeasured container', () => {
    expect(previewScale(0, PREVIEW_DEVICES.desktop)).toBe(1);
  });
});
