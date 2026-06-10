import { Alert, Button, Space } from 'antd';
import { useEffect, useState } from 'react';
import { __ } from '../lib/i18n';
import { useSetupStatus } from '../features/setup/queries';

export function VerifyEmailBanner() {
  const [isVisible, setIsVisible] = useState(false);
  const [isDismissed, setIsDismissed] = useState(false);
  const { data, refetch, isFetching } = useSetupStatus();

  const adminConfig = (window as unknown as { paymentformAdmin?: { emailVerified?: boolean | null } })
    ?.paymentformAdmin;

  const isEmailVerified = adminConfig?.emailVerified === true;

  // Check if banner was dismissed in this session
  useEffect(() => {
    const dismissed = sessionStorage.getItem('paymentform_dismiss_verify_banner');
    if (dismissed) {
      setIsDismissed(true);
    }
  }, []);

  // Show banner only if email is explicitly unverified
  useEffect(() => {
    if (adminConfig?.emailVerified === false && !isDismissed) {
      setIsVisible(true);
    } else {
      setIsVisible(false);
    }
  }, [adminConfig?.emailVerified, isDismissed]);

  // Setup polling for status checks (60s interval)
  useEffect(() => {
    if (!isVisible) return;

    // Enable query for polling
    const unsubscribe = refetch();

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
    setIsVisible(false);
  };

  if (!isVisible || isEmailVerified) {
    return null;
  }

  return (
    <Alert
      message={__('Verify your email', 'paymentform')}
      description={__('Verify your email to enable form publishing, webhook delivery, and payment connects.', 'paymentform')}
      type="warning"
      showIcon
      closable
      onClose={handleDismiss}
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
