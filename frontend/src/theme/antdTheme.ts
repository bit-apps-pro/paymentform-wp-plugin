import { theme } from 'antd';
import type { ThemeConfig } from 'antd';
import type { ThemeMode } from './themeMode';

const BRAND_LIGHT = '#6E62E5';
const BRAND_DARK = '#8B82F0';
/** Shared height for inputs, selects and buttons (QA: "use 42px"). */
const CONTROL_HEIGHT_PX = 42;

export function getThemeConfig(mode: ThemeMode): ThemeConfig {
  const isDark = mode === 'dark';
  return {
    algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
    token: {
      colorPrimary: isDark ? BRAND_DARK : BRAND_LIGHT,
      borderRadius: 8,
      fontFamily: 'inherit',
      controlHeight: CONTROL_HEIGHT_PX,
    },
    components: {
      Layout: {
        headerBg: 'transparent',
        siderBg: 'transparent',
      },
    },
  };
}

export const antdTheme = getThemeConfig('light');
export const antdDarkTheme = getThemeConfig('dark');
