import { theme } from 'antd';
import type { ThemeConfig } from 'antd';

export const antdTheme: ThemeConfig = {
  algorithm: theme.defaultAlgorithm,
  token: {
    colorPrimary: '#2563eb',
    borderRadius: 6,
    fontFamily: 'inherit',
  },
  components: {
    Layout: {
      headerBg: 'transparent',
      siderBg: 'transparent',
    },
  },
};

export const antdDarkTheme: ThemeConfig = {
  ...antdTheme,
  algorithm: theme.darkAlgorithm,
};
