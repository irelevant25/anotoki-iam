import { sameOriginPath } from './next-path';

/** The IAM's own address, as `location.origin` would give it. */
const ORIGIN = 'https://iam.example.test';

describe('sameOriginPath', () => {
  it('keeps a path on this site, with its query and its fragment', () => {
    expect(sameOriginPath('/account/security?x=1#y', ORIGIN)).toBe('/account/security?x=1#y');
    expect(sameOriginPath('/login', ORIGIN)).toBe('/login');
  });

  it('refuses another host, however the address is written', () => {
    for (const value of [
      '//evil.example',
      '/\t/evil.example',
      '/\n/evil.example',
      '/\r/evil.example',
      '/\\evil.example',
      '/.//evil.example',
      '/..//evil.example',
      'https://evil.example/',
      'javascript:alert(1)',
    ]) {
      expect(sameOriginPath(value, ORIGIN)).toBe('/');
    }
  });

  it('refuses nothing at all', () => {
    expect(sameOriginPath('', ORIGIN)).toBe('/');
    expect(sameOriginPath(undefined, ORIGIN)).toBe('/');
    expect(sameOriginPath(null, ORIGIN)).toBe('/');
  });
});
