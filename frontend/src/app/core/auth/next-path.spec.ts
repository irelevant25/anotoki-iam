import { DEFAULT_LEGAL_PATHS } from '@anotoki/lib/legal';
import { LEGAL_PAGES, isLegalPage, safeNext, sameOriginPath } from './next-path';

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

describe('the legal pages', () => {
  it('are the family’s own paths, which provideAnotokiLegal() keeps for the IAM - the Privacy page the one every site links to', () => {
    expect([...LEGAL_PAGES]).toEqual([DEFAULT_LEGAL_PATHS.terms, DEFAULT_LEGAL_PATHS.privacy, DEFAULT_LEGAL_PATHS.contact]);
  });

  it('are known with a query or a fragment, and nothing under or beside them is', () => {
    for (const url of ['/terms', '/privacy', '/contact', '/privacy?lang=sk', '/terms#anotoki-terms-1-age']) {
      expect(isLegalPage(url)).toBe(true);
    }
    for (const url of ['/', '/login', '/account', '/privacy/more', '/privacyx', '/admin/terms']) {
      expect(isLegalPage(url)).toBe(false);
    }
  });

  it('are a place to come back to after signing in', () => {
    expect(safeNext('/privacy')).toBe('/privacy');
    expect(safeNext('/contact?lang=sk')).toBe('/contact?lang=sk');
  });
});
