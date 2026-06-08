import { Result, Button, Spin, Flex, Typography, theme } from 'antd';
import { Wallet } from 'lucide-react';
import { useConnect } from '../features/auth/useConnect';
import { css } from '@emotion/css';

export function Welcome() {
  const { token } = theme.useToken();
  const { mutate: connect, isPending } = useConnect();

  const containerStyles = css`
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 80vh;
    padding: ${token.paddingLG}px;
  `;

  const contentStyles = css`
    width: 100%;
    max-width: 600px;
    text-align: center;
  `;

  return (
    <div className={containerStyles}>
      <div className={contentStyles}>
        {isPending ? (
          <Flex vertical align="center" gap={token.marginMD}>
            <Spin size="large" />
            <Typography.Text type="secondary">Redirecting to PaymentForm…</Typography.Text>
          </Flex>
        ) : (
          <Result
            icon={<Wallet size={64} color={token.colorPrimary} />}
            title="Connect to PaymentForm"
            subTitle="Sign in to your PaymentForm account to manage your forms"
            extra={
              <Button
                type="primary"
                size="large"
                loading={isPending}
                onClick={() => connect()}
              >
                Connect to paymentform.io
              </Button>
            }
          />
        )}
      </div>
    </div>
  );
}
