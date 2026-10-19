import { USERNAME_MAX, usernameFits } from './username';

/** Characters nobody sees, or sees as another: made from their code points, never written into this file as they are. */
const RIGHT_TO_LEFT_OVERRIDE = String.fromCharCode(0x202e);
const ZERO_WIDTH_SPACE = String.fromCharCode(0x200b);
const CYRILLIC_SMALL_A = String.fromCharCode(0x0430);
const COMBINING_CARON = String.fromCharCode(0x030c);

describe('usernameFits', () => {
  it('takes letters and digits, a hundred at most', () => {
    for (const name of ['Frantisek', 'jan2', 'ABC123xyz', '007', 'a', 'a'.repeat(USERNAME_MAX)]) {
      expect(usernameFits(name)).toBe(true);
    }
    expect(USERNAME_MAX).toBe(100);
  });

  it('refuses what could pass for something else - an address, a turn of the text, a space, a dot, an accent, a look-alike of another script', () => {
    for (const name of [
      'jan@example.com',
      `jan${RIGHT_TO_LEFT_OVERRIDE}novak`,
      `${RIGHT_TO_LEFT_OVERRIDE}moc.elpmaxe@naj`,
      'jan novak',
      'jan.novak',
      'janNovák',
      'jan-novak',
      'jan_novak',
      `j${CYRILLIC_SMALL_A}n`,
      `jan${ZERO_WIDTH_SPACE}novak`,
      'jan\tnovak',
      `Frantis${COMBINING_CARON}ek`,
    ]) {
      expect(usernameFits(name)).toBe(false);
    }
  });

  it('refuses nothing at all, a 101st letter, and a line break at either end', () => {
    expect(usernameFits('')).toBe(false);
    expect(usernameFits('a'.repeat(USERNAME_MAX + 1))).toBe(false);
    expect(usernameFits('jan\n')).toBe(false);
    expect(usernameFits('\njan')).toBe(false);
  });
});
