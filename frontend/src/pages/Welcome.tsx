import { Result, Button, Spin } from 'antd';
import { Wallet } from 'lucide-react';
import { useConnect } from '../features/auth/useConnect';
import { css } from '@emotion/css';
import { theme } from 'antd';

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
          <Spin tip="Redirecting to PaymentForm..." />
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
