/** Letters and digits only, so it survives being read out or written down. */
const PASSWORD_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

/**
 * Ported from the genshin admin. Twelve rather than eight characters here:
 * the IAM's minimum length is a setting, and twelve clears any reasonable one.
 */
export const GENERATED_PASSWORD_LENGTH = 12;

/**
 * A password to hand to somebody, meant to be changed once they are in.
 *
 * Twelve characters of this alphabet is around 71 bits - plenty for something
 * typed once and replaced. Drawn from crypto rather than Math.random, which is
 * predictable enough to reconstruct.
 *
 * Values above the largest exact multiple of the alphabet length are thrown
 * away rather than folded with a modulo, which would quietly make the first
 * few letters more likely than the rest.
 */
export function randomPassword(length = GENERATED_PASSWORD_LENGTH): string {
  const alphabet = PASSWORD_ALPHABET.length;
  const ceiling = Math.floor(256 / alphabet) * alphabet;
  const out: string[] = [];

  while (out.length < length) {
    const bytes = new Uint8Array(length);
    crypto.getRandomValues(bytes);
    for (const byte of bytes) {
      if (byte < ceiling && out.length < length) {
        out.push(PASSWORD_ALPHABET[byte % alphabet]);
      }
    }
  }

  return out.join('');
}
