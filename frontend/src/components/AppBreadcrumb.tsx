import { Breadcrumb } from 'antd';
import { useLocation } from 'react-router-dom';
import { __ } from '../lib/i18n';

function labelFor(segment: string): string {
  switch (segment) {
    case 'forms':
      return __('Forms', 'paymentform');
    case 'settings':
      return __('Settings', 'paymentform');
    case 'welcome':
      return __('Welcome', 'paymentform');
    case 'callback':
      return __('Connecting', 'paymentform');
    default:
      return segment;
  }
}

export function AppBreadcrumb() {
  const location = useLocation();
  const segment = location.pathname.replace(/^\//, '').split('/')[0] || 'welcome';
  return (
    <Breadcrumb
      items={[
        { title: __('PaymentForm', 'paymentform') },
        { title: labelFor(segment) },
      ]}
    />
  );
}
