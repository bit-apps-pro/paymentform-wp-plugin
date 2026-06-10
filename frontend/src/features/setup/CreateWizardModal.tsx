import { Modal, Flex, Typography, theme } from 'antd';
import { useEffect, useRef } from 'react';
import { __ } from '../../lib/i18n';
import { defaultCountry } from '../../lib/countries';
import { useWizard } from './state';
import { useSetup } from './queries';
import { WizardCountryStep } from './WizardCountryStep';
import { WizardEmailOverrideStep } from './WizardEmailOverrideStep';
import { WizardDiagnoseStep } from './WizardDiagnoseStep';
import { WizardCheckInboxStep } from './WizardCheckInboxStep';
import { WizardSuccessStep } from './WizardSuccessStep';
import { WizardFailedStep } from './WizardFailedStep';

interface Props {
  open: boolean;
  onClose: () => void;
}

export function CreateWizardModal({ open, onClose }: Props) {
  const { token } = theme.useToken();
  const adminEmail =
    (window as unknown as { paymentformAdmin?: { adminEmail?: string } })?.paymentformAdmin
      ?.adminEmail || '';

  const { state, dispatch } = useWizard(adminEmail, defaultCountry());
  const { mutate: setup, isPending: isSetupPending } = useSetup();

  // Bumped on every modal close so an in-flight /setup request whose user
  // has already closed the dialog can't dispatch against a fresh wizard
  // session that's already on a different step.
  const sessionRef = useRef(0);

  const isSubmitting = state.step === 'submitting' || state.step === 'verifying_otp';
  const isLoading = isSetupPending;

  // Handle setup submission
  useEffect(() => {
    if (state.step === 'submitting') {
      const emailToUse = state.emailOverride || state.email || adminEmail;
      const session = sessionRef.current;

      setup(
        {
          country: state.country,
          locale: state.locale,
          email: emailToUse,
          email_override: state.emailOverride || undefined,
        },
        {
          onSuccess: (data) => {
            // Drop late callbacks from a closed/reset modal so we don't
            // dispatch `connected`/`fail` onto the next session's state.
            if (session !== sessionRef.current) return;

            if (data.next === 'connected') {
              dispatch({
                type: 'connected',
                hasWorkspace: data.has_workspace || false,
              });
            } else if (data.next === 'redirect_connect') {
              dispatch({
                type: 'redirect',
                url: data.connect_url || '',
              });
            } else if (data.next === 'check_inbox') {
              dispatch({ type: 'provisioning-check-pending' });
            } else {
              dispatch({
                type: 'fail',
                code: data.code || 'unknown',
                message: data.message || __('Setup failed', 'paymentform'),
              });
            }
          },
          onError: (error: any) => {
            if (session !== sessionRef.current) return;

            dispatch({
              type: 'fail',
              code: error.errorCode || 'unknown',
              message: error.message || __('Could not set up your account. Try again.', 'paymentform'),
            });
          },
        }
      );
    }
  }, [state.step, state.country, state.locale, state.email, state.emailOverride, adminEmail, setup, dispatch]);

  // Handle redirect on success
  useEffect(() => {
    if (state.connectUrl) {
      window.location.assign(state.connectUrl);
    }
  }, [state.connectUrl]);

  const getModalTitle = (): string => {
    switch (state.step) {
      case 'diagnose':
      case 'diagnose_failed':
        return __('Checking setup…', 'paymentform');
      case 'country':
        return __('Create new account', 'paymentform');
      case 'email_override':
        return __('Confirm your email', 'paymentform');
      case 'submitting':
        return __('Setting up your account…', 'paymentform');
      case 'check_inbox':
        return __('Verify your email', 'paymentform');
      case 'verifying_otp':
        return __('Verifying…', 'paymentform');
      case 'provisioning_check':
        return __('Checking status…', 'paymentform');
      case 'success':
        return __('Success!', 'paymentform');
      case 'failed':
        return __('Something went wrong', 'paymentform');
      default:
        return '';
    }
  };

  const handleCancel = () => {
    // Prevent closing while submitting
    if (!isSubmitting) {
      onClose();
    }
  };

  const handleAfterClose = () => {
    // Invalidate in-flight callbacks before resetting so a late /setup
    // response from the previous open can't move the new session forward.
    sessionRef.current += 1;
    dispatch({ type: 'reset' });
  };

  return (
    <Modal
      title={getModalTitle()}
      open={open}
      onCancel={handleCancel}
      footer={null}
      width={480}
      destroyOnClose
      afterClose={handleAfterClose}
      closable={!isSubmitting}
      maskClosable={!isSubmitting}
      aria-live={state.step === 'submitting' || state.step === 'verifying_otp' ? 'polite' : undefined}
    >
      <Flex vertical gap={token.marginLG} style={{ marginTop: token.marginLG }}>
        {state.step === 'diagnose' && <WizardDiagnoseStep state={state} dispatch={dispatch} />}

        {state.step === 'diagnose_failed' && <WizardDiagnoseStep state={state} dispatch={dispatch} />}

        {state.step === 'country' && <WizardCountryStep state={state} dispatch={dispatch} />}

        {state.step === 'email_override' && <WizardEmailOverrideStep state={state} dispatch={dispatch} />}

        {state.step === 'submitting' && (
          <Flex vertical align="center" justify="center" gap={token.marginLG} style={{ minHeight: '200px' }}>
            <Typography.Text type="secondary" aria-live="polite">
              {__('Creating your workspace…', 'paymentform')}
            </Typography.Text>
          </Flex>
        )}

        {state.step === 'check_inbox' && (
          <WizardCheckInboxStep state={state} dispatch={dispatch} />
        )}

        {state.step === 'provisioning_check' && (
          <Flex vertical align="center" justify="center" gap={token.marginLG} style={{ minHeight: '200px' }}>
            <Typography.Text type="secondary">
              {__('Checking status…', 'paymentform')}
            </Typography.Text>
          </Flex>
        )}

        {state.step === 'success' && (
          <WizardSuccessStep state={state} onClose={onClose} />
        )}

        {state.step === 'failed' && (
          <WizardFailedStep state={state} dispatch={dispatch} />
        )}
      </Flex>
    </Modal>
  );
}
