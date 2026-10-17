import { Button, Flex, Typography, theme } from 'antd';
import { CheckCircle } from 'lucide-react';
import { useLoginLink } from '../auth/useLoginLink';
import { openLoginLink } from '../../lib/openLoginLink';
import { __ } from '../../lib/i18n';
import type { WizardState } from './state';

interface Props {
  state: WizardState;
  onClose: () => void;
}

export function WizardSuccessStep({ state, onClose }: Props) {
  const { token } = theme.useToken();
  const { mutateAsync: mintLoginLink } = useLoginLink();

  // With a ready workspace, "Go to dashboard" mints an SSO link and opens the
  // dashboard, then still closes the modal (preserving CreateWizardModal's
  // close contract). Without one, "Done" just closes — there is nothing to
  // open until the email-verification link is followed.
  const handlePrimary = async () => {
    if (state.hasWorkspace) {
      await openLoginLink(mintLoginLink, '/myforms');
    }
    onClose();
  };

  return (
    <Flex vertical align="center" gap={token.marginLG} style={{ textAlign: 'center' }}>
      <CheckCircle size={48} color={token.colorSuccess} />

      <div>
        <Typography.Title level={3} style={{ margin: 0, marginBottom: token.marginMD }}>
          {state.hasWorkspace
            ? __('Your workspace is ready!', 'paymentform')
            : __('Check your email', 'paymentform')}
        </Typography.Title>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
          {state.hasWorkspace
            ? __('You can now create and manage forms from PaymentForm.', 'paymentform')
            : __('Open the verification link we sent to finish connecting your account.', 'paymentform')}
        </Typography.Paragraph>
      </div>

      <Button
        type="primary"
        onClick={() => {
          void handlePrimary();
        }}
        style={{ width: '100%' }}
      >
        {state.hasWorkspace ? __('Go to dashboard', 'paymentform') : __('Done', 'paymentform')}
      </Button>
    </Flex>
  );
}
