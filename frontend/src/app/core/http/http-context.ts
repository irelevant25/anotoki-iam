import { HttpContext, HttpContextToken } from '@angular/common/http';

/**
 * Marks the request that asks "is anybody signed in?".
 *
 * A 401 from it is the ordinary answer for a visitor, not a session that has
 * just expired, so the interceptor must not treat it as one and bounce the
 * visitor to the sign-in page they may already be on.
 */
export const SESSION_PROBE = new HttpContextToken<boolean>(() => false);

export function probeContext(): HttpContext {
  return new HttpContext().set(SESSION_PROBE, true);
}

/**
 * Marks the request for the site's words (`GET /api/translations/{code}`).
 *
 * Its failure is never a reason to move the page: the words have a last resort
 * of their own (the compiled English), and the request is made while the site
 * starts, before any page exists to be sent away from. So for it the
 * interceptor only reshapes the error - no `/not-set-up`, no session cleared.
 * A page's own first request says `not_set_up` a moment later, and that one
 * does the sending.
 */
export const TRANSLATION_BUNDLE = new HttpContextToken<boolean>(() => false);

export function bundleContext(): HttpContext {
  return new HttpContext().set(TRANSLATION_BUNDLE, true);
}
