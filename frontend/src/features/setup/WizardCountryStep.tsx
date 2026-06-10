import { Select, Button, Flex, Typography, theme } from 'antd';
import { COUNTRIES, defaultCountry } from '../../lib/countries';
import { __ } from '../../lib/i18n';
import type { WizardState, WizardAction } from './state';

interface Props {
  state: WizardState;
  dispatch: React.Dispatch<WizardAction>;
}

export function WizardCountryStep({ state, dispatch }: Props) {
  const { token } = theme.useToken();

  const handleCountryChange = (value: string) => {
    dispatch({ type: 'set-country', value });
  };

  const handleNext = () => {
    dispatch({ type: 'next' });
  };

  const handleUseOtherEmail = () => {
    dispatch({ type: 'set-email-override', value: '' });
    dispatch({ type: 'next' });
  };

  return (
    <Flex vertical gap={token.marginLG}>
      <div>
        <Typography.Title level={4} style={{ margin: 0, marginBottom: token.marginMD }}>
          {__('Where is your business based?', 'paymentform')}
        </Typography.Title>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
          {__('This helps us localize your workspace and comply with local regulations.', 'paymentform')}
        </Typography.Paragraph>
      </div>

      <Select
        placeholder={__('Select a country…', 'paymentform')}
        value={state.country || defaultCountry()}
        onChange={handleCountryChange}
        options={COUNTRIES}
        showSearch
        filterOption={(input, option) =>
          (option?.label ?? '').toLowerCase().includes(input.toLowerCase())
        }
      />

      <Flex gap={token.marginSM} style={{ marginTop: token.marginMD }}>
        <Button type="primary" block onClick={handleNext} disabled={!state.country}>
          {__('Continue', 'paymentform')}
        </Button>
      </Flex>

      <Typography.Text type="secondary" style={{ fontSize: '0.85rem', textAlign: 'center' }}>
        {__('Or', 'paymentform')}{' '}
        <a onClick={handleUseOtherEmail} style={{ cursor: 'pointer' }}>
          {__('use a different email', 'paymentform')}
        </a>
      </Typography.Text>
    </Flex>
  );
}
