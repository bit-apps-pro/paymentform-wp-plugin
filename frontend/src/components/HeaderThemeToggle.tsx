import { Button, Tooltip } from 'antd';
import { Sun, Moon } from 'lucide-react';
import { useThemeMode } from '../theme/themeMode';
import { __ } from '../lib/i18n';

export function HeaderThemeToggle() {
  const mode = useThemeMode((s) => s.mode);
  const toggle = useThemeMode((s) => s.toggle);
  const isDark = mode === 'dark';
  const label = isDark
    ? __('Switch to light mode', 'paymentform')
    : __('Switch to dark mode', 'paymentform');

  return (
    <Tooltip title={label}>
      <Button
        type="text"
        shape="circle"
        aria-label={label}
        onClick={toggle}
        icon={isDark ? <Sun size={16} /> : <Moon size={16} />}
      />
    </Tooltip>
  );
}
