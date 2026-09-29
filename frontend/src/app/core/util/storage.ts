/**
 * localStorage that never throws.
 *
 * It can be missing or refuse writes - a private window, storage switched off,
 * a full quota - and none of what is kept in it here (a theme, a language, a
 * remembered-device token) is worth breaking a sign-in page over. A read that
 * fails is simply "nothing stored".
 */
export function readStorage(key: string): string | null {
  try {
    return globalThis.localStorage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

export function writeStorage(key: string, value: string | null): void {
  try {
    if (value === null) {
      globalThis.localStorage?.removeItem(key);
    } else {
      globalThis.localStorage?.setItem(key, value);
    }
  } catch {
    // Nothing to do: the value just will not survive a reload.
  }
}
