/**
 * Loose on purpose: the server has the last word, this only catches the
 * obvious slip. No dot is required after the @ - `admin@localhost` is a real
 * address on a development machine, and the backend accepts it.
 */
export function looksLikeEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@.][^\s@]*$/.test(value.trim());
}

/** bcrypt reads only the first 72 bytes, so the backend refuses anything longer. */
export const PASSWORD_MAX_BYTES = 72;

export function passwordBytes(value: string): number {
  return new TextEncoder().encode(value).length;
}

/**
 * A translated sentence cut around one placeholder, so the page can set the
 * part in the middle - an application's name - in bold without the sentence
 * being assembled from fragments that only work in English word order.
 */
export function splitAround(text: string, placeholder: string): [string, string] {
  const index = text.indexOf(placeholder);
  return index < 0 ? [text, ''] : [text.slice(0, index), text.slice(index + placeholder.length)];
}

/**
 * Removes one query parameter from the address bar without a navigation.
 *
 * For the secrets that arrive in mailed links: once the page holds the token
 * in memory, it should not stay in the history, be copied along with the URL,
 * or leak to a site that the page links to. The router's own history entry
 * state is kept, so back and forward behave.
 */
export function dropQueryParam(name: string): void {
  try {
    const url = new URL(window.location.href);
    if (url.searchParams.has(name)) {
      url.searchParams.delete(name);
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
  } catch {
    // An address that cannot be parsed has nothing to drop.
  }
}

/** Where a `continue_to` link goes: in-app paths through the router, anything else with a full navigation. */
export function isInternalPath(url: string): boolean {
  return url.startsWith('/') && !url.startsWith('//');
}
