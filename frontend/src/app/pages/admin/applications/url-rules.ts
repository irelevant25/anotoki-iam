/**
 * The same checks the backend makes (routes/admin.php), made early so a typo
 * is marked on its own row instead of coming back as one refusal for the lot.
 */

/** An absolute http(s) URL without a fragment - what a redirect URI may be. */
export function validRedirectUri(uri: string): boolean {
  if (uri.length > 500) {
    return false;
  }
  try {
    const url = new URL(uri);
    return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '' && url.hash === '' && !uri.includes('#');
  } catch {
    return false;
  }
}

/** scheme://host[:port] and nothing more - no path, not even a trailing slash. */
export function validOrigin(origin: string): boolean {
  return /^https?:\/\/[A-Za-z0-9.-]+(:\d{1,5})?$/.test(origin);
}

export const CLIENT_ID_PATTERN = /^[a-z0-9][a-z0-9-]{1,63}$/;
export const ROLE_NAME_PATTERN = /^[A-Z][A-Z0-9_]{0,49}$/;
