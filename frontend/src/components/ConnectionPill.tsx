import { Tag, Skeleton, Tooltip } from 'antd';
import { sprintf } from '@wordpress/i18n';
import { useConnection } from '../features/auth/useConnection';
import { __ } from '../lib/i18n';

/** Caps the workspace name so the header actions fit on phones. */
const PILL_MAX_WIDTH = 'min(200px, 30vw)';

/** Header pill showing connection state and, when connected, which workspace. */
export function ConnectionPill() {
  const { data, isLoading } = useConnection();

  if (isLoading) {
    return <Skeleton.Button active size="small" style={{ width: 96, height: 24 }} />;
  }

  if (!data?.connected) {
    return <Tag color="error">{__('Disconnected', 'paymentform')}</Tag>;
  }

  const label = data.tenant?.name
    ? sprintf(__('Connected · %s', 'paymentform'), data.tenant.name)
    : __('Connected', 'paymentform');

  return (
    <Tooltip title={data.user?.email} placement="bottom">
      <Tag
        color="success"
        style={{ maxWidth: PILL_MAX_WIDTH, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
      >
        {label}
      </Tag>
    </Tooltip>
  );
}
