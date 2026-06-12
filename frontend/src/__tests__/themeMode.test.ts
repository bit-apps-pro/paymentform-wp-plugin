import { beforeEach, describe, expect, it, vi, afterEach } from 'vitest';

const STORAGE_KEY = 'paymentform-ui';

function freshImport() {
  vi.resetModules();
  return import('../theme/themeMode');
}

function setMatchMedia(matches: boolean) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia;
}

describe('themeMode store', () => {
  beforeEach(() => {
    localStorage.clear();
    document.body.className = '';
    setMatchMedia(false);
  });

  afterEach(() => {
    localStorage.clear();
    document.body.className = '';
  });

  it('persisted explicit value wins over OS preference', async () => {
    setMatchMedia(true);
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ state: { mode: 'light', explicit: true }, version: 1 })
    );
    const { useThemeMode } = await freshImport();
    expect(useThemeMode.getState().mode).toBe('light');
    expect(useThemeMode.getState().explicit).toBe(true);
  });

  it('falls back to prefers-color-scheme when no explicit value', async () => {
    setMatchMedia(true);
    const { useThemeMode } = await freshImport();
    expect(useThemeMode.getState().mode).toBe('dark');
    expect(useThemeMode.getState().explicit).toBe(false);
  });

  it('falls back to WP admin dark scheme class when OS preference is light', async () => {
    setMatchMedia(false);
    document.body.classList.add('admin-color-midnight');
    const { useThemeMode } = await freshImport();
    expect(useThemeMode.getState().mode).toBe('dark');
  });

  it('defaults to light when no signal present', async () => {
    setMatchMedia(false);
    const { useThemeMode } = await freshImport();
    expect(useThemeMode.getState().mode).toBe('light');
  });

  it('setMode persists and flags explicit', async () => {
    const { useThemeMode } = await freshImport();
    useThemeMode.getState().setMode('dark');
    expect(useThemeMode.getState().mode).toBe('dark');
    expect(useThemeMode.getState().explicit).toBe(true);
    const raw = localStorage.getItem(STORAGE_KEY);
    expect(raw).toBeTruthy();
    const parsed = JSON.parse(raw!);
    expect(parsed.state.mode).toBe('dark');
    expect(parsed.state.explicit).toBe(true);
  });

  it('toggle flips between dark and light', async () => {
    const { useThemeMode } = await freshImport();
    const start = useThemeMode.getState().mode;
    useThemeMode.getState().toggle();
    expect(useThemeMode.getState().mode).toBe(start === 'dark' ? 'light' : 'dark');
    expect(useThemeMode.getState().explicit).toBe(true);
  });

  it('clearExplicit re-resolves from OS preference', async () => {
    setMatchMedia(true);
    const { useThemeMode } = await freshImport();
    useThemeMode.getState().setMode('light');
    expect(useThemeMode.getState().explicit).toBe(true);
    useThemeMode.getState().clearExplicit();
    expect(useThemeMode.getState().mode).toBe('dark');
    expect(useThemeMode.getState().explicit).toBe(false);
  });
});
