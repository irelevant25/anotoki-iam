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
