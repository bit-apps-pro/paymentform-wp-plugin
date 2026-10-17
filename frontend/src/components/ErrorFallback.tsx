import { Result, Button, Space, App as AntApp } from 'antd';
import { Copy } from 'lucide-react';
import { __ } from '../lib/i18n';
import { copyText } from '../lib/clipboard';
import { wpModuleData } from '../utils/wpData';

interface ErrorFallbackProps {
  error?: Error | null;
  reset?: () => void;
}

export function ErrorFallback({ error, reset }: ErrorFallbackProps = {}) {
  const { message } = AntApp.useApp();

  const copyDiagnostic = async () => {
    const stack = error?.stack ? error.stack.split('\n').slice(0, 5).join('\n') : null;
    const payload = {
      version: wpModuleData.version,
      tenant: wpModuleData.tenant,
      siteUrl: wpModuleData.siteUrl,
      errorName: error?.name,
      errorMessage: error?.message,
      errorStack: stack,
      userAgent: navigator.userAgent,
      at: new Date().toISOString(),
    };
    try {
      await copyText(JSON.stringify(payload, null, 2));
      message.success(__('Copied diagnostic info', 'paymentform'));
    } catch {
      message.error(__('Copy failed', 'paymentform'));
    }
  };

  return (
    <Result
      status="error"
      title={__('Something went wrong', 'paymentform')}
      subTitle={
        error?.message ??
        __('An unexpected error occurred. Try reloading the page.', 'paymentform')
      }
      extra={
        <Space>
          <Button type="primary" onClick={() => window.location.reload()}>
            {__('Reload', 'paymentform')}
          </Button>
          {reset && (
            <Button onClick={reset}>{__('Try again', 'paymentform')}</Button>
          )}
          <Button icon={<Copy size={14} />} onClick={copyDiagnostic}>
            {__('Copy diagnostic info', 'paymentform')}
          </Button>
        </Space>
      }
    />
  );
}
