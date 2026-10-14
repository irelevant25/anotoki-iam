import { readStorage, writeStorage } from '../util/storage';

/** Where it is kept (localStorage) - named on the Privacy page too (core/legal/legal-facts.ts). */
export const DEVICE_TOKEN_KEY = 'anotoki-iam:device-token';

/**
 * The remembered-device token: a browser that answered a two-factor code in
 * the last thirty days and asked not to be asked again.
 *
 * It lives in this browser only and skips the six digits, nothing else - the
 * password or the emailed code is still needed. It is sent with every sign-in
 * so the server can decide; a token it no longer accepts just means the code
 * is asked for again.
 */
export function readDeviceToken(): string | null {
  return readStorage(DEVICE_TOKEN_KEY);
}

export function storeDeviceToken(token: string | null | undefined): void {
  // A sign-in that did not issue a new token leaves the old one in place: null
  // means "nothing new", not "forget this browser".
  if (token) {
    writeStorage(DEVICE_TOKEN_KEY, token);
  }
}

export function forgetDeviceToken(): void {
  writeStorage(DEVICE_TOKEN_KEY, null);
}
