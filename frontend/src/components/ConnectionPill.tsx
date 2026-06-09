import { Tag, Skeleton } from 'antd';
import { useConnection } from '../features/auth/useConnection';
import { __ } from '../lib/i18n';

export function ConnectionPill() {
  const { data, isLoading } = useConnection();

  if (isLoading) {
    return <Skeleton.Button active size="small" style={{ width: 96, height: 24 }} />;
  }

  const connected = data?.connected ?? false;
  return (
    <Tag color={connected ? 'success' : 'error'}>
      {connected ? __('Connected', 'paymentform') : __('Disconnected', 'paymentform')}
    </Tag>
  );
}
