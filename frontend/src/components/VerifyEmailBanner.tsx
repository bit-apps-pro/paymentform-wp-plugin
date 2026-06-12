import { Alert, Button, Space } from 'antd';
import { useEffect, useState } from 'react';
import { __ } from '../lib/i18n';
import { useSetupStatus } from '../features/setup/queries';
import { wpModuleData } from '../utils/wpData';

export function VerifyEmailBanner() {
  // Read the per-session dismissal once on mount via a lazy initializer.
  const [isDismissed, setIsDismissed] = useState(
    () => sessionStorage.getItem('paymentform_dismiss_verify_banner') !== null
  );
  const { refetch, isFetching } = useSetupStatus();

  const isEmailVerified = wpModuleData.emailVerified === true;

  // Derived during render: show only while the email is explicitly unverified
  // and the banner hasn't been dismissed this session.
  const isVisible = wpModuleData.emailVerified === false && !isDismissed;

  // Poll status while the banner is visible (immediate refetch + 60s interval).
  useEffect(() => {
    if (!isVisible) return;

    refetch();
    const timer = setInterval(() => {
      refetch();
    }, 60000); // 60 seconds

    return () => {
      clearInterval(timer);
    };
  }, [isVisible, refetch]);

  const handleDismiss = () => {
    sessionStorage.setItem('paymentform_dismiss_verify_banner', Date.now().toString());
    setIsDismissed(true);
  };

  if (!isVisible || isEmailVerified) {
    return null;
  }

  return (
    <Alert
      title={__('Verify your email', 'paymentform')}
      description={__('Verify your email to enable form publishing, webhook delivery, and payment connects.', 'paymentform')}
      type="warning"
      showIcon
      closable={{ onClose: handleDismiss }}
      action={
        <Space size="small">
          <Button
            size="small"
            type="text"
            onClick={() => refetch()}
            loading={isFetching}
          >
            {__('Refresh', 'paymentform')}
          </Button>
        </Space>
      }
      style={{ marginBottom: 16 }}
    />
  );
}
