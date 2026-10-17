/** Rendered height of the fixed WP admin bar in px, or 0 when the page has none. */
export function adminBarHeight(): number {
  return document.getElementById('wpadminbar')?.offsetHeight ?? 0;
}
