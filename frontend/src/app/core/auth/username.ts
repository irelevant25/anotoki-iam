/** The longest a username may be (the server's USERNAME_MAX: users.username is VARCHAR(100)). */
export const USERNAME_MAX = 100;

/** Letters and digits only - a-z, A-Z, 0-9 -, one to a hundred of them. */
const USERNAME_RULE = new RegExp(`^[A-Za-z0-9]{1,${USERNAME_MAX}}$`);

/**
 * Whether a name keeps the rule every username keeps since the owner's
 * decision of 2026-10-19: letters and digits only, at most 100 - so that no
 * name looks like an email address or turns the direction of the text. The
 * server is the rule (`usernameRefusal()`, backend/api/helpers.php); the forms
 * ask it first only to say a slip before a request.
 *
 * An account keeps a name from before the rule, so a form asks it of a new
 * name alone: one sent back unchanged is no change, and the server does not
 * ask either.
 */
export function usernameFits(name: string): boolean {
  return USERNAME_RULE.test(name);
}
