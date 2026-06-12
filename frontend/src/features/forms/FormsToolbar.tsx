import { type ReactNode } from 'react';
import { Input, Select, Segmented, Button, Flex, theme } from 'antd';
import { RefreshCw, Search } from 'lucide-react';
import { __ } from '../../lib/i18n';
import type { Density, StatusFilter, FormsToolbarValue } from './useFormsToolbarState';

export type { Density, StatusFilter, FormsToolbarValue } from './useFormsToolbarState';

interface FormsToolbarProps {
  value: FormsToolbarValue;
  onChange: (next: FormsToolbarValue) => void;
  onRefresh: () => void;
  isRefreshing?: boolean;
  /** Right-aligned content (e.g. primary action button). */
  extra?: ReactNode;
}

export function FormsToolbar({
  value,
  onChange,
  onRefresh,
  isRefreshing,
  extra,
}: FormsToolbarProps) {
  const { token } = theme.useToken();

  return (
    <Flex
      align="center"
      justify="space-between"
      gap={token.marginSM}
      wrap="wrap"
      style={{ marginBottom: token.marginMD }}
    >
      <Flex align="center" gap={token.marginSM} wrap="wrap">
        <Input
          allowClear
          prefix={<Search size={14} color={token.colorTextTertiary} />}
          placeholder={__('Search forms', 'paymentform')}
          value={value.q}
          onChange={(e) => onChange({ ...value, q: e.target.value })}
          style={{ width: 260 }}
        />
        <Select
          value={value.status}
          onChange={(status) => onChange({ ...value, status: status as StatusFilter })}
          style={{ width: 160 }}
          options={[
            { label: __('All statuses', 'paymentform'), value: 'all' },
            { label: __('Enabled', 'paymentform'), value: 'enabled' },
            { label: __('Disabled', 'paymentform'), value: 'disabled' },
            { label: __('Draft', 'paymentform'), value: 'draft' },
          ]}
        />
        <Segmented
          value={value.density}
          onChange={(density) => onChange({ ...value, density: density as Density })}
          options={[
            { label: __('Comfortable', 'paymentform'), value: 'default' },
            { label: __('Compact', 'paymentform'), value: 'compact' },
          ]}
        />
        <Button
          icon={<RefreshCw size={14} />}
          onClick={onRefresh}
          loading={isRefreshing}
        >
          {__('Refresh', 'paymentform')}
        </Button>
      </Flex>
      {extra ? <Flex align="center">{extra}</Flex> : null}
    </Flex>
  );
}
