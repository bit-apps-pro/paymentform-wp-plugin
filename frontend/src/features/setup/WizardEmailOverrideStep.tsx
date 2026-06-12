import { Input, Button, Flex, Typography, theme, Space } from 'antd';
import { useState } from 'react';
import { __ } from '../../lib/i18n';
import type { WizardState, WizardAction } from './state';
import { wpModuleData } from '../../utils/wpData';

interface Props {
  state: WizardState;
  dispatch: React.Dispatch<WizardAction>;
}

export function WizardEmailOverrideStep({ dispatch }: Props) {
  const { token } = theme.useToken();
  const [showInput, setShowInput] = useState(false);
  const [customEmail, setCustomEmail] = useState('');
  const adminEmail = wpModuleData.adminEmail || '';

  const isValidEmail = (email: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

  const handleContinue = () => {
    dispatch({ type: 'set-email', value: adminEmail });
    dispatch({ type: 'set-email-override', value: customEmail });
    dispatch({ type: 'next' });
  };

  const handleCancel = () => {
    setShowInput(false);
    setCustomEmail('');
    dispatch({ type: 'back' });
  };

  return (
    <Flex vertical gap={token.marginLG}>
      <div>
        <Typography.Title level={4} style={{ margin: 0, marginBottom: token.marginMD }}>
          {__('Confirm your email', 'paymentform')}
        </Typography.Title>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
          {__('This is where we will send your workspace details and verification link.', 'paymentform')}
        </Typography.Paragraph>
      </div>

      <div style={{
        padding: token.paddingMD,
        backgroundColor: token.colorBgContainer,
        borderRadius: token.borderRadiusSM,
      }}>
        <Typography.Text strong>{adminEmail}</Typography.Text>
      </div>

      {!showInput && (
        <a onClick={() => setShowInput(true)} style={{ cursor: 'pointer' }}>
          {__('Use a different email', 'paymentform')}
        </a>
      )}

      {showInput && (
        <Input
          type="email"
          placeholder={__('Enter your email', 'paymentform')}
          value={customEmail}
          onChange={(e) => setCustomEmail(e.target.value)}
          status={customEmail && !isValidEmail(customEmail) ? 'error' : ''}
        />
      )}

      <Space style={{ marginTop: token.marginMD }}>
        <Button type="primary" onClick={handleContinue} disabled={!adminEmail && (!customEmail || !isValidEmail(customEmail))}>
          {__('Continue', 'paymentform')}
        </Button>
        <Button onClick={handleCancel}>
          {__('Back', 'paymentform')}
        </Button>
      </Space>
    </Flex>
  );
}
