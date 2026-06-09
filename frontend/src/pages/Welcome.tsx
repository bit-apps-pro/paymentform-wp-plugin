import { Card, Button, Spin, Flex, Typography, List, theme } from 'antd';
import { CheckCircle } from 'lucide-react';
import { css } from '@emotion/css';
import { Logo } from '../components/Logo';
import { useConnect } from '../features/auth/useConnect';
import { __ } from '../lib/i18n';

export function Welcome() {
  const { token } = theme.useToken();
  const { mutate: connect, isPending } = useConnect();

  const containerStyles = css`
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 70vh;
    padding: ${token.paddingLG}px;
  `;

  const cardStyles = css`
    width: 100%;
    max-width: 560px;
  `;

  if (isPending) {
    return (
      <div className={containerStyles}>
        <Flex vertical align="center" gap={token.marginMD}>
          <Spin size="large" />
          <Typography.Text type="secondary">
            {__('Redirecting to PaymentForm…', 'paymentform')}
          </Typography.Text>
        </Flex>
      </div>
    );
  }

  const permissions = [
    __('Read your forms', 'paymentform'),
    __('Receive submission webhooks', 'paymentform'),
    __('Refresh your access token automatically', 'paymentform'),
  ];

  return (
    <div className={containerStyles}>
      <Card className={cardStyles}>
        <Flex vertical align="center" gap={token.marginMD} style={{ textAlign: 'center' }}>
          <Logo size={72} />
          <Typography.Title level={2} style={{ margin: 0 }}>
            {__('Connect your site to PaymentForm', 'paymentform')}
          </Typography.Title>
          <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
            {__(
              'Embed payment forms, receive submissions, and manage your workspace without leaving WordPress.',
              'paymentform'
            )}
          </Typography.Paragraph>
        </Flex>
        <List
          size="small"
          style={{ marginTop: token.marginLG }}
          dataSource={permissions}
          renderItem={(item) => (
            <List.Item>
              <Flex align="center" gap={token.marginSM}>
                <CheckCircle size={16} color={token.colorSuccess} />
                <Typography.Text>{item}</Typography.Text>
              </Flex>
            </List.Item>
          )}
        />
        <Flex justify="center" style={{ marginTop: token.marginLG }}>
          <Button type="primary" size="large" loading={isPending} onClick={() => connect()}>
            {__('Connect to paymentform.io', 'paymentform')}
          </Button>
        </Flex>
      </Card>
    </div>
  );
}
