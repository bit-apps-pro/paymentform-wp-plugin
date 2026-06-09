import { Button } from 'antd';
import { Plug } from 'lucide-react';
import { useConnection } from '../features/auth/useConnection';
import { useConnect } from '../features/auth/useConnect';
import { __ } from '../lib/i18n';

export function HeaderConnectButton() {
  const { data, isLoading } = useConnection();
  const { mutate: connect, isPending } = useConnect();

  if (isLoading || data?.connected) return null;

  return (
    <Button
      type="primary"
      size="small"
      icon={<Plug size={14} />}
      loading={isPending}
      onClick={() => connect()}
    >
      {__('Connect', 'paymentform')}
    </Button>
  );
}
