import { Alert, Button, Flex, Space, theme } from 'antd';
import { ShieldAlert } from 'lucide-react';
import { __ } from '../../lib/i18n';
import { useConnect } from '../auth/useConnect';
import type { WizardAction } from './state';

interface Props {
  dispatch: React.Dispatch<WizardAction>;
}

/** Tells the admin the email owner refused this site and offers another email or Connect. */
export function WizardDeniedStep({ dispatch }: Props) {
  const { token } = theme.useToken();
  const { mutate: connect, isPending: isConnecting } = useConnect();

  return (
    <Flex vertical gap={token.marginLG}>
      <Alert
        title={__('The owner of this email chose not to give this site access. Use a different email, or sign in with Connect if the account is yours.', 'paymentform')}
        type="warning"
        showIcon
        icon={<ShieldAlert size={20} />}
      />

      <Space orientation="vertical" style={{ width: '100%' }}>
        <Button type="primary" block onClick={() => dispatch({ type: 'change-email' })}>
          {__('Use a different email', 'paymentform')}
        </Button>

        <Button type="default" block onClick={() => connect()} loading={isConnecting}>
          {__('Use Connect instead', 'paymentform')}
        </Button>
      </Space>
    </Flex>
  );
}
