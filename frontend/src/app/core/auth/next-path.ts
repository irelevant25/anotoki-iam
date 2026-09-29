export const DEFAULT_NEXT = '/account';

/**
 * Where to go after signing in, when the IAM's own pages were opened directly.
 *
 * Only a path on this site is accepted. `//evil.example` is a path to a
 * browser's parser but a host to its address bar, and a backslash is read as a
 * slash by some of them, so both are refused along with anything absolute.
 * Pages that only exist to sign somebody in are refused too - arriving back on
 * /login after signing in would be a loop, not a destination.
 */
export function safeNext(value: string | null | undefined): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) {
    return DEFAULT_NEXT;
  }

  const path = value.split(/[?#]/)[0];
  const authPages = ['/login', '/logout', '/register', '/confirm-email', '/forgot-password', '/reset-password', '/error', '/not-set-up'];
  if (authPages.some((page) => path === page || path.startsWith(page + '/'))) {
    return DEFAULT_NEXT;
  }

  return value;
}

/** Whether a URL belongs to the part of the site that needs a session. */
export function isSessionArea(url: string): boolean {
  const path = url.split(/[?#]/)[0];
  return ['/account', '/admin'].some((area) => path === area || path.startsWith(area + '/'));
}

/** Whether a URL is in the admin console, which is English only. */
export function isAdminArea(url: string): boolean {
  const path = url.split(/[?#]/)[0];
  return path === '/admin' || path.startsWith('/admin/');
}
