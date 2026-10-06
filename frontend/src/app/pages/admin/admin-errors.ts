import { ApiError } from '../../core/api/api-error';

/**
 * An error as the admin panel says it: the server's own sentence, which is
 * English and specific ("This is the only IAM administrator left"), with a
 * plainer line for the failures that have no sentence of their own.
 */
export function adminErrorText(error: ApiError): string {
  if (error.status === 0) {
    return 'The server could not be reached. Check the connection and try again.';
  }
  if (error.code === 'forbidden') {
    return 'Only IAM administrators can do this.';
  }
  // Migration 010 not applied yet: the applications pages have no sign-in
  // rules to show until it is. And 012: the approvals have nothing to list;
  // 013: announcements have nowhere to be kept.
  if (error.code === 'sign_in_rules_unavailable' || error.code === 'announcements_unavailable') {
    return 'Apply the pending update first: Admin panel → Migrations.';
  }
  if (error.status >= 500 && (!error.message || error.message === 'Internal Server Error')) {
    return 'Something went wrong on the server. Try again, and check the PHP error log if it keeps happening.';
  }
  return error.message || 'The request failed.';
}
