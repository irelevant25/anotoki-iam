import { SessionEntry } from '../../core/api/models';
import { TranslationKey } from '../../core/i18n/en';

const METHODS: Record<string, TranslationKey> = {
  password: 'sessions.method.password',
  login_code: 'sessions.method.login_code',
  google: 'sessions.method.google',
  email_link: 'sessions.method.email_link',
};

const ENDED: Record<string, TranslationKey> = {
  signed_out: 'sessions.ended.signed_out',
  signed_out_elsewhere: 'sessions.ended.signed_out_elsewhere',
  password_changed: 'sessions.ended.password_changed',
  security_change: 'sessions.ended.security_change',
  replaced: 'sessions.ended.replaced',
  ended_by_admin: 'sessions.ended.ended_by_admin',
  account_disabled: 'sessions.ended.account_disabled',
  account_deletion: 'sessions.ended.account_deletion',
};

/** How a session was signed in, as a key - the stored method names are the backend's, not words. */
export function sessionMethodKey(method: string): TranslationKey {
  return METHODS[method] ?? 'sessions.method.other';
}

/**
 * Live, or how it ended. A reason the frontend has no words for still reads
 * as "Ended" rather than as its raw code; the code is in the row's title.
 */
export function sessionStateKey(session: SessionEntry): TranslationKey {
  if (session.current) {
    return 'sessions.thisDevice';
  }
  if (session.active) {
    return 'sessions.active';
  }
  if (session.revoked_reason) {
    return ENDED[session.revoked_reason] ?? 'sessions.ended.other';
  }
  return 'sessions.expired';
}
