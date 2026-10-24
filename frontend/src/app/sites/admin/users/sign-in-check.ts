import { FilterApplication } from '../../../api/models';

/**
 * The applications a set of grants would leave somebody blocked from - the
 * role editor's "Cannot sign in" warning, worked out as the server works out
 * `blocked_in` (routes/admin.php, blockedFromSql()).
 *
 * Blocked from a site means: none of the roles held there may sign in, and
 * either MEMBER is not held there (taken away) or another role is. Somebody
 * holding just MEMBER where MEMBER may not sign in (a members-only site) was
 * simply never invited, and is not warned about - or every ordinary account
 * would be. The IAM's own entry is never checked: Settings -> Signing in is
 * its gate.
 *
 * `selected` holds grant keys (`client_id:ROLE`, the role picker's); `implied`
 * names roles to count as held everywhere although they are not ticked - the
 * create dialog's MEMBER, which the database gives every new account.
 *
 * @returns client ids, in the order of `applications`
 */
export function blockedApplications(applications: readonly FilterApplication[], selected: readonly string[], implied: readonly string[] = []): string[] {
  return applications
    .filter((application) => {
      if (application.system) {
        return false;
      }
      const prefix = `${application.client_id}:`;
      const held = new Set([...implied, ...selected.filter((key) => key.startsWith(prefix)).map((key) => key.slice(prefix.length))]);
      if ([...held].some((role) => application.sign_in_roles.includes(role))) {
        return false;
      }
      return !held.has('MEMBER') || [...held].some((role) => role !== 'MEMBER');
    })
    .map((application) => application.client_id);
}
