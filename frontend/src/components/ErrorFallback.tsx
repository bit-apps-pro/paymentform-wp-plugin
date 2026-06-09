import { Result, Button } from 'antd';
import { __ } from '../lib/i18n';

export function ErrorFallback() {
  return (
    <Result
      status="error"
      title={__('Something went wrong', 'paymentform')}
      subTitle={__('An unexpected error occurred. Try refreshing the page.', 'paymentform')}
      extra={
        <Button type="primary" onClick={() => window.location.reload()}>
          {__('Reload', 'paymentform')}
        </Button>
      }
    />
  );
}
