import { Card, Descriptions, Button, Space, Popconfirm, Spin, Empty, Flex, Typography, theme } from 'antd';
import { dateI18n } from '@wordpress/date';
import { useConnection } from '../features/auth/useConnection';
import { useRefreshToken } from '../features/auth/useRefreshToken';
import { useDisconnect } from '../features/auth/useDisconnect';
import { css } from '@emotion/css';

export function Settings() {
  const { token } = theme.useToken();
  const { data: connection, isLoading } = useConnection();
  const { mutate: refresh, isPending: isRefreshing } = useRefreshToken();
  const { mutate: disconnect, isPending: isDisconnecting } = useDisconnect();

  const containerStyles = css`
    max-width: 800px;
    margin: 0 auto;
  `;

  const cardStyles = css`
    margin-bottom: ${token.marginLG}px;
  `;

  if (isLoading) {
    return (
      <div className={containerStyles}>
        <Flex vertical align="center" gap={token.marginMD} style={{ paddingTop: token.paddingXL }}>
          <Spin size="large" />
          <Typography.Text type="secondary">Loading connection status…</Typography.Text>
        </Flex>
      </div>
    );
  }

  if (!connection?.connected) {
    return (
      <div className={containerStyles}>
        <Empty
          description="Not connected"
          style={{ marginTop: token.marginLG }}
        />
      </div>
    );
  }

  const expiresAtTime = connection.expiresAt
    ? dateI18n('F j, Y at g:i A', connection.expiresAt)
    : 'Unknown';

  const expiresInDays = connection.expiresIn
    ? Math.ceil(connection.expiresIn / (24 * 60 * 60))
    : 0;

  return (
    <div className={containerStyles}>
      <Card title="Connection Status" className={cardStyles}>
        <Descriptions column={1} size="small">
          {connection.user && (
            <>
              <Descriptions.Item label="User Email">
                {connection.user.email}
              </Descriptions.Item>
              <Descriptions.Item label="User Name">
                {connection.user.name}
              </Descriptions.Item>
            </>
          )}
          {connection.tenant && (
            <Descriptions.Item label="Tenant">
              {connection.tenant.name}
            </Descriptions.Item>
          )}
          {connection.expiresAt && (
            <>
              <Descriptions.Item label="Token Expires">
                {expiresAtTime}
              </Descriptions.Item>
              <Descriptions.Item label="Expires In">
                {expiresInDays} day{expiresInDays !== 1 ? 's' : ''}
              </Descriptions.Item>
            </>
          )}
        </Descriptions>
      </Card>

      <Card>
        <Space>
          <Button
            type="primary"
            loading={isRefreshing}
            onClick={() => refresh()}
          >
            Refresh Token
          </Button>
          <Popconfirm
            title="Disconnect"
            description="Are you sure you want to disconnect from PaymentForm?"
            onConfirm={() => disconnect()}
            okText="Yes"
            cancelText="No"
          >
            <Button danger loading={isDisconnecting}>
              Disconnect
            </Button>
          </Popconfirm>
        </Space>
      </Card>
    </div>
  );
}
