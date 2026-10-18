import { useReducer } from 'react';

export type WizardStep =
  | 'diagnose'
  | 'diagnose_failed'
  | 'country'
  | 'email_override'
  | 'submitting'
  | 'check_inbox'
  | 'verifying_otp'
  | 'provisioning_check'
  | 'success'
  | 'failed';

export interface WizardState {
  step: WizardStep;
  country: string;
  email: string;
  emailOverride: string;
  locale: string;
  hasWorkspace: boolean;
  failureCode?: string;
  failureMessage?: string;
  connectUrl?: string;
  diagnoseError?: {
    dns?: string;
    reachable?: boolean;
    tlsValid?: boolean;
    statusCode?: number;
    proofEndpoint?: string;
  };
  resendAfterSeconds?: number;
  otpAttempts?: number;
}

export type WizardAction =
  | { type: 'set-country'; value: string }
  | { type: 'set-email'; value: string }
  | { type: 'set-email-override'; value: string }
  | { type: 'set-locale'; value: string }
  | { type: 'diagnose-start' }
  | { type: 'diagnose-ok' }
  | { type: 'diagnose-failed'; error: WizardState['diagnoseError'] }
  | { type: 'next' }
  | { type: 'back' }
  | { type: 'submitting' }
  | { type: 'fail'; code: string; message: string }
  | { type: 'connected'; hasWorkspace: boolean }
  | { type: 'redirect'; url: string }
  | { type: 'verify-otp-start' }
  | { type: 'verify-otp-success'; hasWorkspace: boolean }
  | { type: 'verify-otp-failed' }
  | { type: 'provisioning-check-start' }
  | { type: 'provisioning-check-success'; hasWorkspace: boolean }
  | { type: 'provisioning-check-pending' }
  | { type: 'reset' };

const initialState: WizardState = {
  step: 'diagnose',
  country: '',
  email: '',
  emailOverride: '',
  locale: 'en_US',
  hasWorkspace: false,
  otpAttempts: 0,
};

export function wizardReducer(state: WizardState, action: WizardAction): WizardState {
  switch (action.type) {
    case 'set-country':
      return { ...state, country: action.value };

    case 'set-email':
      return { ...state, email: action.value };

    case 'set-email-override':
      return { ...state, emailOverride: action.value };

    case 'set-locale':
      return { ...state, locale: action.value };

    case 'diagnose-start':
      return { ...state, step: 'diagnose' };

    case 'diagnose-ok':
      return { ...state, step: 'country', diagnoseError: undefined };

    case 'diagnose-failed':
      return { ...state, step: 'diagnose_failed', diagnoseError: action.error };

    case 'next': {
      // Step-specific transitions
      if (state.step === 'diagnose_failed') {
        return { ...state, step: 'diagnose' };
      }
      if (state.step === 'country') {
        return { ...state, step: 'email_override' };
      }
      if (state.step === 'email_override') {
        return { ...state, step: 'submitting' };
      }
      return state;
    }

    case 'back': {
      if (state.step === 'email_override') {
        return { ...state, step: 'country', emailOverride: '' };
      }
      return state;
    }

    case 'submitting':
      return { ...state, step: 'submitting' };

    case 'fail':
      return {
        ...state,
        step: 'failed',
        failureCode: action.code,
        failureMessage: action.message,
      };

    case 'connected':
      return {
        ...state,
        step: 'success',
        hasWorkspace: action.hasWorkspace,
        failureCode: undefined,
        failureMessage: undefined,
      };

    case 'redirect':
      return {
        ...state,
        step: 'failed', // Use failed as redirect state (will navigate away)
        connectUrl: action.url,
      };

    case 'verify-otp-start':
      return { ...state, step: 'verifying_otp' };

    case 'verify-otp-success':
      return {
        ...state,
        step: 'success',
        hasWorkspace: action.hasWorkspace,
      };

    case 'verify-otp-failed':
      return {
        ...state,
        step: 'check_inbox',
        otpAttempts: (state.otpAttempts || 0) + 1,
      };

    case 'provisioning-check-start':
      return { ...state, step: 'provisioning_check' };

    case 'provisioning-check-success':
      return {
        ...state,
        step: 'success',
        hasWorkspace: action.hasWorkspace,
      };

    case 'provisioning-check-pending':
      return { ...state, step: 'check_inbox' };

    case 'reset':
      return {
        ...initialState,
        country: state.country, // Preserve country selection
        locale: state.locale,
        step: 'country', // Go back to country, skipping diagnose
      };

    default:
      return state;
  }
}

export function useWizard(initialEmail: string = '', defaultCountry: string = '') {
  const [state, dispatch] = useReducer(wizardReducer, {
    ...initialState,
    email: initialEmail,
    country: defaultCountry || initialState.country,
  });

  return { state, dispatch };
}
