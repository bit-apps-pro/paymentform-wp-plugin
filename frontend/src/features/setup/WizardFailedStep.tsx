import { Button, Flex, Typography, theme, Space, Alert } from 'antd';
import { AlertCircle } from 'lucide-react';
import { __ } from '../../lib/i18n';
import { useConnect } from '../auth/useConnect';
import type { WizardState, WizardAction } from './state';

interface Props {
  state: WizardState;
  dispatch: React.Dispatch<WizardAction>;
}

export function WizardFailedStep({ state, dispatch }: Props) {
  const { token } = theme.useToken();
  const { mutate: connect, isPending: isConnecting } = useConnect();

  const handleTryAgain = () => {
    dispatch({ type: 'reset' });
  };

  const handleUseConnect = () => {
    connect();
  };

  const getErrorMessage = (): string => {
    if (state.failureMessage) return state.failureMessage;

    const code: string = state.failureCode || 'unknown';
    const messages: Record<string, string> = {
      service_unavailable: __('The PaymentForm service is currently unavailable. Please try again later.', 'paymentform'),
      network: __('Could not reach paymentform.io. Check your hosting firewall settings.', 'paymentform'),
      proof_dns_nxdomain: __('We could not find a DNS record for your domain. Confirm your site URL is correct.', 'paymentform'),
      proof_tls_invalid: __("Your site's SSL certificate is not trusted. Renew it or use HTTP for testing.", 'paymentform'),
      proof_redirect_refused: __('Your site redirected our verification request. Disable redirect plugins on the setup endpoint.', 'paymentform'),
      proof_timeout: __('Your site took too long to respond. Check your server performance.', 'paymentform'),
      setup_token_salts_rotated: __('Your WordPress security keys were rotated. Please try again.', 'paymentform'),
      unknown: __('An unexpected error occurred. Please try again.', 'paymentform'),
    };

    return messages[code] || __('An unexpected error occurred. Please try again.', 'paymentform');
  };

  const isServiceUnavailable = state.failureCode === 'service_unavailable';

  return (
    <Flex vertical gap={token.marginLG}>
      <Alert
        title={__('Something went wrong', 'paymentform')}
        description={getErrorMessage()}
        type="error"
        showIcon
        icon={<AlertCircle size={20} />}
        style={{ marginBottom: token.marginMD }}
      />

      <Space orientation="vertical" style={{ width: '100%' }}>
        <Button
          type="primary"
          block
          onClick={handleTryAgain}
          disabled={isServiceUnavailable}
        >
          {__('Try again', 'paymentform')}
        </Button>

        <Button
          type="default"
          block
          onClick={handleUseConnect}
          loading={isConnecting}
        >
          {__('Use Connect instead', 'paymentform')}
        </Button>
      </Space>

      {isServiceUnavailable && (
        <Typography.Text type="warning" style={{ fontSize: '0.85rem' }}>
          {__('Service will be available again in about an hour.', 'paymentform')}
        </Typography.Text>
      )}
    </Flex>
  );
}
