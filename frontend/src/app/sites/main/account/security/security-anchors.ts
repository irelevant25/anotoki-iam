/**
 * The parts of the Security page that signing in again comes back to - their
 * headings' ids, and the fragment of the way back (FreshSignInService).
 */
export const SECURITY_ANCHORS = {
  /** The ways in: password, its switch, Google. */
  methods: 'sign-in-methods',
  /** Two-factor authentication. */
  twoFactor: 'two-factor',
  /** Deleting the account. */
  deletion: 'deletion',
} as const;
