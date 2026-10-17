import { theme } from 'antd';
import type { ThemeConfig } from 'antd';
import type { ThemeMode } from './themeMode';

const BRAND_LIGHT = '#6E62E5';
const BRAND_DARK = '#8B82F0';
/** Shared height for inputs, selects and buttons (QA: "use 42px"). */
const CONTROL_HEIGHT_PX = 42;
const HEADER_HEIGHT_PX = 64;
const HEADER_PADDING = '0 24px';

/** antd theme for the admin SPA in the given colour mode. */
export function getThemeConfig(mode: ThemeMode): ThemeConfig {
  const isDark = mode === 'dark';
  const base: ThemeConfig = {
    algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
    token: {
      colorPrimary: isDark ? BRAND_DARK : BRAND_LIGHT,
      borderRadius: 8,
      fontFamily: 'inherit',
      controlHeight: CONTROL_HEIGHT_PX,
    },
  };
  const { colorBgContainer } = theme.getDesignToken(base);

  return {
    ...base,
    components: {
      // Layout derives header size from controlHeight, and hashPriority="high" lets these
      // tokens outrank AppShell's Emotion classes, so the shell is pinned here.
      Layout: {
        headerHeight: HEADER_HEIGHT_PX,
        headerPadding: HEADER_PADDING,
        headerBg: colorBgContainer,
        siderBg: colorBgContainer,
      },
    },
  };
}

export const antdTheme = getThemeConfig('light');
export const antdDarkTheme = getThemeConfig('dark');
