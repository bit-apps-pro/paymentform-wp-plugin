import { useState } from 'react';
import {
  Card,
  Descriptions,
  Button,
  Typography,
  theme,
  Segmented,
  Flex,
  Tag,
  App as AntApp,
} from 'antd';
import { css } from '@emotion/css';
import { sprintf } from '@wordpress/i18n';
import { CheckCircle, XCircle } from 'lucide-react';
import { useThemeMode } from '../theme/themeMode';
import { request } from '../lib/request/request';
import { __ } from '../lib/i18n';

type HealthRow = { endpoint: string; ok: boolean; ms: number; error?: string };

export function Settings() {
  const { token } = theme.useToken();
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
      timestamp: new Date().toISOString(),
      pluginVersion: window.paymentformAdmin?.version ?? 'unknown',
      siteUrl: window.location.origin,
      userAgent: navigator.userAgent,
    };
    await navigator.clipboard.writeText(JSON.stringify(info, null, 2));
    message.success(__('Copied diagnostic info', 'paymentform'));
  };

  return (
    <div className={containerStyles}>
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
              href="https://paymentform.io/contact"
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
