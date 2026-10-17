import { App, Input, Button, Flex, Typography, theme, Space } from 'antd';
import { useState, useEffect } from 'react';
import { __ } from '../../lib/i18n';
import { useVerifyOtp, useResendOtp, useSetupStatus } from './queries';
import type { WizardState, WizardAction } from './state';

interface Props {
  state: WizardState;
  dispatch: React.Dispatch<WizardAction>;
}

function maskEmail(email: string): string {
  const [local, domain] = email.split('@');
  if (!local || !domain) return email;
  return `${local[0]}***@${domain}`;
}

export function WizardCheckInboxStep({ state, dispatch }: Props) {
  const { token } = theme.useToken();
  const { message } = App.useApp();
  const [showOtpInput, setShowOtpInput] = useState(false);
  const [otp, setOtp] = useState('');
  const [resendCountdown, setResendCountdown] = useState(0);

  const { mutate: verifyOtp, isPending: isVerifying } = useVerifyOtp();
  const { mutate: resendOtp, isPending: isResending } = useResendOtp();
  const {
    refetch: checkStatus,
    data: statusData,
    isFetching: isCheckingStatus,
  } = useSetupStatus(true, 3000);

  const masked = maskEmail(state.emailOverride || state.email);
  const isLoading = isVerifying || isCheckingStatus;

  // Auto-promote to success once the backend confirms the connected envelope
  // — the WP-side bridge has already persisted the access token by the time
  // this poll returns `next:'connected'`, so the wizard only needs to flip
  // its UI state to success.
  useEffect(() => {
    if (statusData?.next === 'connected') {
      dispatch({
        type: 'verify-otp-success',
        hasWorkspace: statusData.has_workspace ?? state.hasWorkspace,
      });
    }
  }, [statusData?.next, statusData?.has_workspace, state.hasWorkspace, dispatch]);

  // Countdown timer for resend
  useEffect(() => {
    if (resendCountdown <= 0) return;
    const timer = setTimeout(() => setResendCountdown(resendCountdown - 1), 1000);
    return () => clearTimeout(timer);
  }, [resendCountdown]);

  const handleCheckEmail = async () => {
    try {
      const result = await checkStatus();
      const next = result.data?.next;
      if (next === 'connected') {
        // The auto-promote effect will fire too, but trigger immediately so
        // the click feels responsive.
        dispatch({
          type: 'verify-otp-success',
          hasWorkspace: result.data?.has_workspace ?? state.hasWorkspace,
        });
      } else if (result.data?.email_verified) {
        message.info(__('Still finishing the workspace setup — hang tight.', 'paymentform'));
      } else {
        message.info(__('Email not yet verified. Check your inbox for the verification link.', 'paymentform'));
      }
    } catch {
      message.error(__('Could not check status. Try again.', 'paymentform'));
    }
  };

  const handleVerifyOtp = () => {
    if (!otp || otp.length !== 6) {
      message.error(__('Please enter a 6-digit code.', 'paymentform'));
      return;
    }

    verifyOtp(
      { otp },
      {
        onSuccess: (data) => {
          if (data.verified) {
            // Email is verified, but the access token is only minted once a
            // setup/status poll runs finalize. Kick the status check (the 3s
            // poller + auto-promote effect then carry the wizard to
            // 'connected') instead of jumping to success before the token
            // exists — otherwise the wizard unmounts this poller and strands
            // the user on a "check your email" screen while disconnected.
            void checkStatus();
          } else {
            message.error(data.error || __('Invalid code. Please try again.', 'paymentform'));
            dispatch({ type: 'verify-otp-failed' });
          }
        },
        onError: () => {
          // Only structural failures (unknown/expired token, flag-off,
          // transport) reach here — a wrong/expended code rides the 200
          // onSuccess branch above with its own message.
          message.error(__('Could not verify code. Try again.', 'paymentform'));
          dispatch({ type: 'verify-otp-failed' });
        },
      }
    );
  };

  const handleResendOtp = () => {
    resendOtp(undefined, {
        onSuccess: (data) => {
          message.success(__('Verification code sent.', 'paymentform'));
          if (data.resend_after_seconds) {
            setResendCountdown(data.resend_after_seconds);
          }
        },
        onError: () => {
          message.error(__('Could not resend code. Try again.', 'paymentform'));
        },
      }
    );
  };

  return (
    <Flex vertical gap={token.marginLG}>
      <div>
        <Typography.Title level={4} style={{ margin: 0, marginBottom: token.marginMD }}>
          {__('Check your inbox', 'paymentform')}
        </Typography.Title>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
          {__('We sent a verification link to', 'paymentform')}{' '}
          <Typography.Text strong code>{masked}</Typography.Text>
          {'.'}
        </Typography.Paragraph>
      </div>

      <Space orientation="vertical" style={{ width: '100%', gap: token.marginMD }}>
        <Button
          type="default"
          block
          onClick={handleCheckEmail}
          loading={isCheckingStatus}
          disabled={isLoading}
        >
          {__('I clicked the link in my email', 'paymentform')}
        </Button>

        {!showOtpInput ? (
          <a
            onClick={() => setShowOtpInput(true)}
            style={{ cursor: 'pointer', textAlign: 'center', display: 'block' }}
          >
            {__('Or enter a code instead', 'paymentform')}
          </a>
        ) : (
          <>
            <Input
              placeholder={__('000000', 'paymentform')}
              value={otp}
              onChange={(e) => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
              maxLength={6}
              style={{ textAlign: 'center', letterSpacing: '0.5em', fontSize: '1.25rem' }}
            />
            <Button
              type="primary"
              block
              onClick={handleVerifyOtp}
              loading={isVerifying}
              disabled={otp.length !== 6 || isLoading}
            >
              {__('Verify', 'paymentform')}
            </Button>
          </>
        )}

        <div style={{ textAlign: 'center' }}>
          <Button
            type="text"
            size="small"
            onClick={handleResendOtp}
            disabled={resendCountdown > 0 || isResending}
            loading={isResending}
          >
            {resendCountdown > 0
              ? __('Resend in', 'paymentform') + ` ${resendCountdown}s`
              : __('Resend code', 'paymentform')}
          </Button>
        </div>
      </Space>

      <Typography.Text type="secondary" style={{ fontSize: '0.85rem' }}>
        {__('If you cannot find the email, check your spam folder. The link works on any device.', 'paymentform')}
      </Typography.Text>
    </Flex>
  );
}
