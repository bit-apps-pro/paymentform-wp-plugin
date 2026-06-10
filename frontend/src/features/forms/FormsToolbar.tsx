import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Input, Select, Segmented, Button, Flex, theme } from 'antd';
import { RefreshCw, Search } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
import { __ } from '../../lib/i18n';

export type Density = 'compact' | 'default';
export type StatusFilter = 'all' | 'enabled' | 'disabled' | 'draft';

export interface FormsToolbarValue {
  q: string;
  status: StatusFilter;
  density: Density;
}

interface FormsToolbarProps {
  value: FormsToolbarValue;
  onChange: (next: FormsToolbarValue) => void;
  onRefresh: () => void;
  isRefreshing?: boolean;
  /** Right-aligned content (e.g. primary action button). */
  extra?: ReactNode;
}

const URL_DEBOUNCE_MS = 300;

export function useFormsToolbarState(): {
  value: FormsToolbarValue;
  setValue: (next: FormsToolbarValue) => void;
} {
  const [params, setParams] = useSearchParams();
  const initial: FormsToolbarValue = {
    q: params.get('q') ?? '',
    status: (params.get('status') as StatusFilter) ?? 'all',
    density: (params.get('density') as Density) ?? 'default',
  };
  const [value, setLocal] = useState<FormsToolbarValue>(initial);
  const timer = useRef<number | undefined>(undefined);

  const setValue = (next: FormsToolbarValue) => {
    setLocal(next);
    if (timer.current) window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => {
      const sp = new URLSearchParams();
      if (next.q) sp.set('q', next.q);
      if (next.status !== 'all') sp.set('status', next.status);
      if (next.density !== 'default') sp.set('density', next.density);
      setParams(sp, { replace: true });
    }, URL_DEBOUNCE_MS);
  };

  useEffect(() => () => {
    if (timer.current) window.clearTimeout(timer.current);
  }, []);

  return { value, setValue };
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
