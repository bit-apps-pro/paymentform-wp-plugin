/** Viewport widths (px) the embed preview can emulate. */
export const PREVIEW_DEVICES = { mobile: 390, tablet: 820, desktop: 1280 } as const;

export type PreviewDevice = keyof typeof PREVIEW_DEVICES;

/** Scale that fits a device-width frame into the container without ever upscaling. */
export function previewScale(containerWidth: number, deviceWidth: number): number {
  if (containerWidth <= 0 || deviceWidth <= 0) return 1;
  return Math.min(1, containerWidth / deviceWidth);
}
