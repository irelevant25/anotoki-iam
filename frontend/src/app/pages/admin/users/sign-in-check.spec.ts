import { FilterApplication } from '../../../core/api/models';
import { blockedApplications } from './sign-in-check';

/** A site whose every role may sign in, one where only ADMIN may (members-only), and the IAM. */
const OPEN: FilterApplication = { client_id: 'survey', name: 'Surveys', system: false, roles: ['ADMIN', 'EDITOR', 'MEMBER'], sign_in_roles: ['ADMIN', 'EDITOR', 'MEMBER'] };
const MEMBERS_ONLY: FilterApplication = { client_id: 'piano-academy', name: 'Piano Academy', system: false, roles: ['ADMIN', 'EDITOR', 'MEMBER'], sign_in_roles: ['ADMIN'] };
const IAM: FilterApplication = { client_id: 'iam', name: 'anotoki account', system: true, roles: ['ADMIN', 'MEMBER'], sign_in_roles: ['ADMIN', 'MEMBER'] };
const ALL = [IAM, OPEN, MEMBERS_ONLY];

describe('blockedApplications', () => {
  it('warns about every site where nothing at all is held - MEMBER taken away', () => {
    expect(blockedApplications(ALL, [])).toEqual(['survey', 'piano-academy']);
  });

  it('lets MEMBER in where MEMBER may sign in', () => {
    expect(blockedApplications([OPEN], ['survey:MEMBER'])).toEqual([]);
  });

  it('does not warn about MEMBER alone where MEMBER may not sign in: not invited is not blocked', () => {
    expect(blockedApplications([MEMBERS_ONLY], ['piano-academy:MEMBER'])).toEqual([]);
  });

  it('warns when the roles held there are all ones that may not sign in', () => {
    expect(blockedApplications([MEMBERS_ONLY], ['piano-academy:MEMBER', 'piano-academy:EDITOR'])).toEqual(['piano-academy']);
    expect(blockedApplications([MEMBERS_ONLY], ['piano-academy:EDITOR'])).toEqual(['piano-academy']);
  });

  it('does not warn when one role held may sign in', () => {
    expect(blockedApplications([MEMBERS_ONLY], ['piano-academy:EDITOR', 'piano-academy:ADMIN'])).toEqual([]);
  });

  it('never warns about the IAM itself', () => {
    expect(blockedApplications([IAM], [])).toEqual([]);
  });

  it('counts only the grants of each site', () => {
    expect(blockedApplications([OPEN, MEMBERS_ONLY], ['piano-academy:ADMIN'])).toEqual(['survey']);
    expect(blockedApplications([OPEN], ['survey-old:MEMBER'])).toEqual(['survey']);
  });

  it('counts an implied MEMBER as held: the create dialog warns about nothing while nothing is ticked', () => {
    expect(blockedApplications(ALL, [], ['MEMBER'])).toEqual([]);
  });

  it('still warns in the create dialog about a role ticked that may not sign in, MEMBER implied', () => {
    expect(blockedApplications(ALL, ['piano-academy:EDITOR'], ['MEMBER'])).toEqual(['piano-academy']);
  });
});
