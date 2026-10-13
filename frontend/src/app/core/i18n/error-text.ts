import { ApiError } from '../api/api-error';
import { TranslationKey } from './en';

/**
 * The backend's error codes, mapped to a sentence in the reader's language.
 *
 * Only codes whose meaning does not depend on where they came from are here.
 * `invalid_token` is the odd one out: from the Google endpoints it means the
 * Google sign-in could not be verified, from the mailed-link endpoints that
 * the link is dead - so the pages that can receive it decide for themselves
 * before falling back to this.
 */
const BY_CODE: Record<string, TranslationKey> = {
  network: 'error.network',
  server_error: 'error.server',
  csrf: 'error.csrf',
  forbidden: 'error.forbidden',
  not_found: 'error.notFound',
  invalid_credentials: 'error.invalidCredentials',
  password_login_unavailable: 'error.passwordLoginUnavailable',
  totp_invalid: 'error.totpInvalid',
  authenticator_paused: 'error.authenticatorPaused',
  email_not_confirmed: 'error.emailNotConfirmed',
  login_disabled: 'error.loginDisabled',
  account_disabled: 'error.accountDisabled',
  too_many_attempts: 'error.tooManyAttempts',
  invalid_code: 'error.invalidCode',
  google_disabled: 'error.googleDisabled',
  google_taken: 'error.googleTaken',
  google_already_connected: 'error.googleAlreadyConnected',
  unverified_email: 'error.unverifiedEmail',
  registration_disabled: 'error.registrationDisabled',
  request_expired: 'error.linkInvalid',
  would_lock_account: 'error.wouldLockAccount',
  no_password: 'error.noPassword',
  wrong_password: 'error.wrongPassword',
  invalid_password: 'error.invalidPassword',
  invalid_username: 'error.invalidUsername',
  invalid_email: 'common.invalidEmail',
  username_taken: 'error.usernameTaken',
  email_taken: 'error.emailTaken',
  approval_pending: 'error.approvalPending',
  account_deletion_pending: 'error.accountDeletionPending',
  reauthentication_required: 'deletion.freshSignIn',
};

/**
 * The key for an error, when there is one.
 *
 * For a code this frontend does not know, the answer is null and the caller
 * shows the server's own sentence - English, but accurate, which beats a vague
 * translated "something went wrong".
 */
export function errorKey(error: ApiError): TranslationKey | null {
  const byCode = BY_CODE[error.code];
  if (byCode) {
    return byCode;
  }
  if (error.status === 0) {
    return 'error.network';
  }
  if (error.status === 429) {
    return 'error.tooManyAttempts';
  }
  if (error.status >= 500) {
    return 'error.server';
  }
  return null;
}

/** A sentence for any error: translated when the code is known, the server's words otherwise. */
export function errorText(error: ApiError, t: (key: TranslationKey) => string): string {
  const key = errorKey(error);
  if (key) {
    return t(key);
  }
  return error.message || t('error.generic');
}
