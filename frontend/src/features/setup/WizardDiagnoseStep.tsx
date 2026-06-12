import { Spin, Button, Flex, Typography, theme, Space } from 'antd';
import { useEffect } from 'react';
import { AlertCircle, CheckCircle } from 'lucide-react';
import { __ } from '../../lib/i18n';
import { useDiagnose } from './queries';
import type { WizardState, WizardAction } from './state';

interface Props {
  state: WizardState;
  dispatch: React.Dispatch<WizardAction>;
}

export function WizardDiagnoseStep({ state, dispatch }: Props) {
  const { token } = theme.useToken();
  const { mutate: diagnose, isPending } = useDiagnose();

  useEffect(() => {
    if (state.step === 'diagnose') {
      diagnose(undefined, {
        onSuccess: (data) => {
          // Backend returns { next: 'ok' } | { next: 'failed', code, message }.
          // Phase B's WP controller may also pass through a rich shape from a future
          // backend `/api/auth/setup/diagnose` endpoint (dns/reachable/tls_valid/proof_endpoint).
          // Treat both shapes correctly: prefer `next` if present, else fall through to
          // the legacy field-by-field check.
          if (data.next === 'ok') {
            dispatch({ type: 'diagnose-ok' });
            return;
          }
          if (data.next === 'failed') {
            dispatch({
              type: 'diagnose-failed',
              error: {
                reachable: false,
                ...((data as unknown) as Record<string, unknown>),
              },
            });
            return;
          }
          // Legacy rich-shape fallback.
          const hasErrors =
            data.dns === 'nxdomain' ||
            data.dns === 'servfail' ||
            !data.reachable ||
            data.tls_valid === false ||
            data.proof_endpoint === 'missing' ||
            data.proof_endpoint === 'rewrite_unflushed';
          if (hasErrors) {
            dispatch({ type: 'diagnose-failed', error: data });
          } else {
            dispatch({ type: 'diagnose-ok' });
          }
        },
        onError: () => {
          dispatch({
            type: 'diagnose-failed',
            error: { reachable: false },
          });
        },
      });
    }
  }, [state.step, diagnose, dispatch]);

  if (state.step === 'diagnose') {
    return (
      <Flex vertical align="center" justify="center" gap={token.marginLG} style={{ minHeight: '200px' }}>
        <Spin size="large" />
        <Typography.Text type="secondary">
          {__("Checking your site's reachability…", 'paymentform')}
        </Typography.Text>
      </Flex>
    );
  }

  if (state.step === 'diagnose_failed' && state.diagnoseError) {
    return (
      <Flex vertical gap={token.marginLG}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          {__('We found an issue', 'paymentform')}
        </Typography.Title>

        <Flex vertical gap={token.marginMD}>
          {/* DNS check */}
          <DiagnoseItem
            label={__('DNS', 'paymentform')}
            status={state.diagnoseError.dns === 'ok' ? 'success' : 'error'}
            message={
              state.diagnoseError.dns === 'nxdomain'
                ? __('We could not find a DNS record for your domain. Confirm your site URL is correct.', 'paymentform')
                : state.diagnoseError.dns === 'servfail'
                ? __('DNS server failure. Try again in a moment.', 'paymentform')
                : __('DNS resolution successful.', 'paymentform')
            }
          />

          {/* Reachability check */}
          <DiagnoseItem
            label={__('Reachability', 'paymentform')}
            status={state.diagnoseError.reachable ? 'success' : 'error'}
            message={
              !state.diagnoseError.reachable
                ? __('We could not verify your WordPress site is publicly reachable. Ensure it is accessible from the internet.', 'paymentform')
                : __('Your site is reachable.', 'paymentform')
            }
          />

          {/* TLS check */}
          {state.diagnoseError.tlsValid !== undefined && (
            <DiagnoseItem
              label={__('SSL Certificate', 'paymentform')}
              status={state.diagnoseError.tlsValid ? 'success' : 'error'}
              message={
                !state.diagnoseError.tlsValid
                  ? __("Your site's SSL certificate is not trusted. Renew it or use HTTP for testing.", 'paymentform')
                  : __('SSL certificate is valid.', 'paymentform')
              }
            />
          )}

          {/* Proof endpoint check */}
          <DiagnoseItem
            label={__('Setup Endpoint', 'paymentform')}
            status={state.diagnoseError.proofEndpoint === 'live' ? 'success' : 'error'}
            message={
              state.diagnoseError.proofEndpoint === 'missing'
                ? __('The setup verification endpoint is missing. Make sure WordPress is fully installed.', 'paymentform')
                : state.diagnoseError.proofEndpoint === 'rewrite_unflushed'
                ? __('WordPress permalinks need to be flushed. Go to Settings and click Save.', 'paymentform')
                : __('Setup endpoint is live.', 'paymentform')
            }
          />
        </Flex>

        <Space orientation="vertical" style={{ width: '100%', marginTop: token.marginLG }}>
          <Button type="primary" block onClick={() => dispatch({ type: 'diagnose-start' })} loading={isPending}>
            {__('Try again', 'paymentform')}
          </Button>
        </Space>
      </Flex>
    );
  }

  return null;
}

function DiagnoseItem({
  label,
  status,
  message,
}: {
  label: string;
  status: 'success' | 'error';
  message: string;
}) {
  const { token } = theme.useToken();
  const Icon = status === 'success' ? CheckCircle : AlertCircle;
  const color = status === 'success' ? token.colorSuccess : token.colorError;

  return (
    <Flex gap={token.marginMD} align="flex-start">
      <Icon size={16} color={color} style={{ flexShrink: 0, marginTop: 4 }} />
      <Flex vertical gap={token.marginXS} style={{ flex: 1 }}>
        <Typography.Text strong>{label}</Typography.Text>
        <Typography.Text type={status === 'success' ? undefined : 'danger'} style={{ fontSize: '0.85rem' }}>
          {message}
        </Typography.Text>
      </Flex>
    </Flex>
  );
}
