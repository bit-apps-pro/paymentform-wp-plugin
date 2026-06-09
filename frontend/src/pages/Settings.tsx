import { useState } from 'react';
import {
  Card,
  Descriptions,
  Button,
  Space,
  Popconfirm,
  Skeleton,
  Typography,
  theme,
  Row,
  Col,
  Statistic,
  Tag,
  Segmented,
  Flex,
  App as AntApp,
} from 'antd';
import { dateI18n } from '@wordpress/date';
import { sprintf, _n } from '@wordpress/i18n';
import { css } from '@emotion/css';
import { CheckCircle, XCircle, RefreshCw } from 'lucide-react';
import { useConnection } from '../features/auth/useConnection';
import { useRefreshToken } from '../features/auth/useRefreshToken';
import { useDisconnect } from '../features/auth/useDisconnect';
import { useThemeMode } from '../theme/themeMode';
import { ExpiresCountdown } from '../components/ExpiresCountdown';
import { request } from '../lib/request/request';
import { __ } from '../lib/i18n';
import { useQueryClient } from '@tanstack/react-query';

type HealthRow = { endpoint: string; ok: boolean; ms: number; error?: string };

function expiryTag(days: number): { color: string; text: string } {
  if (days > 14) return { color: 'success', text: __('Healthy', 'paymentform') };
  if (days > 3) return { color: 'warning', text: __('Renew soon', 'paymentform') };
  return { color: 'error', text: __('Expiring', 'paymentform') };
}

export function Settings() {
  const { token } = theme.useToken();
  const queryClient = useQueryClient();
  const { data: connection, isLoading } = useConnection();
  const { mutate: refresh, isPending: isRefreshing } = useRefreshToken();
  const { mutate: disconnect, isPending: isDisconnecting } = useDisconnect();
  const themeMode = useThemeMode((s) => s.mode);
  const explicit = useThemeMode((s) => s.explicit);
  const setMode = useThemeMode((s) => s.setMode);
  const clearExplicit = useThemeMode((s) => s.clearExplicit);
  const { message } = AntApp.useApp();

  const [healthRows, setHealthRows] = useState<HealthRow[]>([]);
  const [isChecking, setIsChecking] = useState(false);

  const containerStyles = css`
    max-width: 880px;
    margin: 0 auto;
  `;

  const cardStyles = css`
    margin-bottom: ${token.marginLG}px;
  `;

  if (isLoading) {
    return (
      <div className={containerStyles}>
        <Card className={cardStyles}>
          <Skeleton active paragraph={{ rows: 4 }} />
        </Card>
      </div>
    );
  }

  const isConnected = !!connection?.connected;

  const expiresAtText = connection?.expiresAt
    ? dateI18n('F j, Y g:i A', connection.expiresAt)
    : __('Unknown', 'paymentform');

  const expiresInDays = connection?.expiresIn
    ? Math.ceil(connection.expiresIn / (24 * 60 * 60))
    : 0;
  const tag = expiryTag(expiresInDays);

  const connectionState = queryClient.getQueryState(['connection']);
  const lastRefreshed = connectionState?.dataUpdatedAt
    ? dateI18n('g:i A', new Date(connectionState.dataUpdatedAt).toISOString())
    : '—';

  const segmentedValue: 'light' | 'dark' | 'system' = explicit ? themeMode : 'system';

  const onAppearanceChange = (value: string | number) => {
    if (value === 'system') {
      clearExplicit();
    } else {
      setMode(value as 'light' | 'dark');
    }
  };

  const runHealthCheck = async () => {
    setIsChecking(true);
    const targets = ['/status', '/forms'];
    const rows: HealthRow[] = [];
    for (const endpoint of targets) {
      const start = performance.now();
      try {
        await request<unknown>(endpoint, { method: 'GET' });
        rows.push({ endpoint, ok: true, ms: Math.round(performance.now() - start) });
      } catch (err) {
        const e = err as { message?: string };
        rows.push({
          endpoint,
          ok: false,
          ms: Math.round(performance.now() - start),
          error: e?.message ?? __('Request failed', 'paymentform'),
        });
      }
    }
    setHealthRows(rows);
    setIsChecking(false);
  };

  const onCopyDiagnostic = async () => {
    const info = {
      version: window.paymentformAdmin?.version,
      siteUrl: window.paymentformAdmin?.siteUrl,
      tenant: connection?.tenant?.name,
      user: connection?.user?.email,
      expiresAt: connection?.expiresAt,
    };
    await navigator.clipboard.writeText(JSON.stringify(info, null, 2));
    message.success(__('Copied diagnostic info', 'paymentform'));
  };

  return (
    <div className={containerStyles}>
      {isConnected ? (
      <Card
        title={__('Connection', 'paymentform')}
        extra={
          <Space.Compact>
            <Button
              icon={<RefreshCw size={14} />}
              loading={isRefreshing}
              onClick={() => refresh()}
            >
              {__('Refresh token', 'paymentform')}
            </Button>
            <Popconfirm
              title={__('Disconnect from PaymentForm?', 'paymentform')}
              description={__('You will need to reconnect to manage forms.', 'paymentform')}
              onConfirm={() => disconnect()}
              okText={__('Disconnect', 'paymentform')}
              cancelText={__('Cancel', 'paymentform')}
              okButtonProps={{ danger: true }}
            >
              <Button danger loading={isDisconnecting}>
                {__('Disconnect', 'paymentform')}
              </Button>
            </Popconfirm>
          </Space.Compact>
        }
        className={cardStyles}
      >
        <Row gutter={[token.marginLG, token.marginMD]}>
          <Col xs={24} sm={12} md={6}>
            <Statistic
              title={__('User', 'paymentform')}
              value={connection?.user?.email ?? '—'}
              styles={{ content: { fontSize: token.fontSizeLG } }}
            />
          </Col>
          <Col xs={24} sm={12} md={6}>
            <Statistic
              title={__('Workspace', 'paymentform')}
              value={connection.tenant?.name ?? '—'}
              styles={{ content: { fontSize: token.fontSizeLG } }}
            />
          </Col>
          <Col xs={24} sm={12} md={6}>
            <ExpiresCountdown
              value={connection?.expiresAt}
              title={__('Token expires in', 'paymentform')}
            />
            <Space size="small" style={{ marginTop: token.marginXS }}>
              <Tag color={tag.color}>{tag.text}</Tag>
              <Typography.Text type="secondary">
                {connection?.expiresAt
                  ? sprintf(
                      _n('%d day left', '%d days left', expiresInDays, 'paymentform'),
                      expiresInDays
                    )
                  : __('Unknown', 'paymentform')}
              </Typography.Text>
            </Space>
            <div>
              <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                {expiresAtText}
              </Typography.Text>
            </div>
          </Col>
          <Col xs={24} sm={12} md={6}>
            <Statistic
              title={__('Last refreshed', 'paymentform')}
              value={lastRefreshed}
              styles={{ content: { fontSize: token.fontSizeLG } }}
            />
          </Col>
        </Row>
      </Card>
      ) : (
        <Card className={cardStyles}>
          <Typography.Text type="secondary">
            {__(
              'Not connected. Open the Welcome page to connect this site to PaymentForm.',
              'paymentform'
            )}
          </Typography.Text>
        </Card>
      )}

      <Card title={__('Appearance', 'paymentform')} className={cardStyles}>
        <Segmented
          value={segmentedValue}
          onChange={onAppearanceChange}
          options={[
            { label: __('Light', 'paymentform'), value: 'light' },
            { label: __('Dark', 'paymentform'), value: 'dark' },
            { label: __('System', 'paymentform'), value: 'system' },
          ]}
        />
        <div style={{ marginTop: token.marginSM }}>
          <Typography.Text type="secondary">
            {__(
              'System follows your OS preference and falls back to your WP admin scheme.',
              'paymentform'
            )}
          </Typography.Text>
        </div>
      </Card>

      <Card title={__('Plugin info', 'paymentform')} className={cardStyles}>
        <Descriptions column={{ xs: 1, sm: 2 }} size="small">
          <Descriptions.Item label={__('Plugin version', 'paymentform')}>
            {window.paymentformAdmin?.version ?? '—'}
          </Descriptions.Item>
          <Descriptions.Item label={__('Site URL', 'paymentform')}>
            {window.paymentformAdmin?.siteUrl ?? '—'}
          </Descriptions.Item>
          <Descriptions.Item label={__('Support', 'paymentform')}>
            <a
              href="https://paymentform.io/support"
              target="_blank"
              rel="noopener noreferrer"
            >
              {__('Contact support', 'paymentform')}
            </a>
          </Descriptions.Item>
          <Descriptions.Item label={__('Diagnostics', 'paymentform')}>
            <Button size="small" onClick={onCopyDiagnostic}>
              {__('Copy diagnostic info', 'paymentform')}
            </Button>
          </Descriptions.Item>
        </Descriptions>
      </Card>

      <Card title={__('Health check', 'paymentform')} className={cardStyles}>
        <Flex vertical gap={token.marginMD} style={{ width: '100%' }}>
          <Button
            type="primary"
            loading={isChecking}
            onClick={runHealthCheck}
            style={{ alignSelf: 'flex-start' }}
          >
            {__('Run health check', 'paymentform')}
          </Button>
          {healthRows.length > 0 && (
            <Flex vertical gap={token.marginXS} style={{ width: '100%' }}>
              {healthRows.map((row) => (
                <Flex key={row.endpoint} align="center" gap={token.marginSM}>
                  {row.ok ? (
                    <CheckCircle size={16} color={token.colorSuccess} />
                  ) : (
                    <XCircle size={16} color={token.colorError} />
                  )}
                  <Typography.Text code>{row.endpoint}</Typography.Text>
                  <Tag color={row.ok ? 'success' : 'error'}>
                    {sprintf(
                      __('%d ms', 'paymentform'),
                      row.ms
                    )}
                  </Tag>
                  {row.error && (
                    <Typography.Text type="danger" style={{ fontSize: token.fontSizeSM }}>
                      {row.error}
                    </Typography.Text>
                  )}
                </Flex>
              ))}
            </Flex>
          )}
        </Flex>
      </Card>
    </div>
  );
}
