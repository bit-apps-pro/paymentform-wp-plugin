import { describe, expect, it } from 'vitest';
import { SETUP_DENIED_CODE, wizardReducer, type WizardState } from '../features/setup/state';

const denied: WizardState = {
  step: 'failed',
  country: 'US',
  email: 'admin@example.test',
  emailOverride: 'someone@example.test',
  locale: 'fr_FR',
  hasWorkspace: false,
  failureCode: SETUP_DENIED_CODE,
  failureMessage: 'denied',
  otpAttempts: 2,
};

describe('wizardReducer', () => {
  it('change-email reopens the email step with a clean slate', () => {
    const next = wizardReducer(denied, { type: 'change-email' });

    expect(next.step).toBe('email_override');
    expect(next.country).toBe('US');
    expect(next.locale).toBe('fr_FR');
    expect(next.emailOverride).toBe('');
    expect(next.failureCode).toBeUndefined();
    expect(next.otpAttempts).toBe(0);
  });

  it('reset still returns to the country step', () => {
    expect(wizardReducer(denied, { type: 'reset' }).step).toBe('country');
  });
});
