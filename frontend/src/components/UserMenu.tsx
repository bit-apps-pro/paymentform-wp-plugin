import { Dropdown, Avatar, Button, App as AntApp } from 'antd';
import type { MenuProps } from 'antd';
import { ExternalLink, LogOut } from 'lucide-react';
import { useConnection } from '../features/auth/useConnection';
import { useDisconnect } from '../features/auth/useDisconnect';
import { __ } from '../lib/i18n';

function initialsFrom(name?: string): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p.charAt(0).toUpperCase()).join('') || '?';
}

export function UserMenu() {
  const { data } = useConnection();
  const { mutate: disconnect } = useDisconnect();
  const { modal } = AntApp.useApp();

  if (!data?.connected) return null;

  const appBaseUrl = window.paymentformAdmin?.appBaseUrl ?? 'https://app.paymentform.io';

  const items: MenuProps['items'] = [
    {
      key: 'open',
      icon: <ExternalLink size={14} />,
      label: (
        <a href={appBaseUrl} target="_blank" rel="noopener noreferrer">
          {__('Open paymentform.io', 'paymentform')}
        </a>
      ),
    },
    { type: 'divider' },
    {
      key: 'disconnect',
      icon: <LogOut size={14} />,
      label: __('Disconnect', 'paymentform'),
      danger: true,
      onClick: () => {
        modal.confirm({
          title: __('Disconnect from PaymentForm?', 'paymentform'),
          content: __('You will need to reconnect to manage your forms.', 'paymentform'),
          okText: __('Disconnect', 'paymentform'),
          okButtonProps: { danger: true },
          cancelText: __('Cancel', 'paymentform'),
          onOk: () => disconnect(),
        });
      },
    },
  ];

  const name = data.user?.name || data.user?.email || '';

  return (
    <Dropdown menu={{ items }} placement="bottomRight" trigger={['click']}>
      <Button
        type="text"
        shape="circle"
        aria-label={__('Account menu', 'paymentform')}
        icon={<Avatar size="small">{initialsFrom(name)}</Avatar>}
      />
    </Dropdown>
  );
}
