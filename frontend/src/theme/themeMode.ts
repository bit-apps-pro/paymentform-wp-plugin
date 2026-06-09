import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

export type ThemeMode = 'light' | 'dark';

type ThemeModeState = {
  mode: ThemeMode;
  explicit: boolean;
  setMode: (mode: ThemeMode) => void;
  clearExplicit: () => void;
  toggle: () => void;
};

const WP_DARK_SCHEME_CLASSES = ['admin-color-midnight', 'admin-color-ectoplasm', 'admin-color-coffee'];

export function resolveDefaultMode(): ThemeMode {
  if (typeof window === 'undefined') return 'light';
  try {
    if (window.matchMedia?.('(prefers-color-scheme: dark)').matches) return 'dark';
  } catch {
    // matchMedia unavailable; fall through
  }
  if (typeof document !== 'undefined' && document.body?.classList) {
    for (const cls of WP_DARK_SCHEME_CLASSES) {
      if (document.body.classList.contains(cls)) return 'dark';
    }
  }
  return 'light';
}

export const useThemeMode = create<ThemeModeState>()(
  persist(
    (set, get) => ({
      mode: resolveDefaultMode(),
      explicit: false,
      setMode: (mode) => set({ mode, explicit: true }),
      clearExplicit: () => set({ mode: resolveDefaultMode(), explicit: false }),
      toggle: () => set({ mode: get().mode === 'dark' ? 'light' : 'dark', explicit: true }),
    }),
    {
      name: 'paymentform-ui',
      version: 1,
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({ mode: state.mode, explicit: state.explicit }),
      merge: (persisted, current) => {
        const p = (persisted ?? {}) as Partial<ThemeModeState>;
        if (p.explicit && (p.mode === 'light' || p.mode === 'dark')) {
          return { ...current, mode: p.mode, explicit: true };
        }
        return { ...current, mode: resolveDefaultMode(), explicit: false };
      },
    }
  )
);
