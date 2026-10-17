import { describe, it, expect } from 'vitest';
import { theme } from 'antd';
import { getThemeConfig } from '../theme/antdTheme';

describe('getThemeConfig', () => {
  for (const mode of ['light', 'dark'] as const) {
    it(`pins the ${mode} app shell header and sider independently of controlHeight`, () => {
      const config = getThemeConfig(mode);
      const { colorBgContainer } = theme.getDesignToken(config);

      expect(config.components?.Layout).toMatchObject({
        headerHeight: 64,
        headerPadding: '0 24px',
        headerBg: colorBgContainer,
        siderBg: colorBgContainer,
      });
    });
  }
});
