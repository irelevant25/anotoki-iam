import { inject } from '@angular/core';
import { CanDeactivateFn } from '@angular/router';
import { SessionService } from '../../../core/auth/session.service';

/** A page with changes that are kept only when saved: it says whether it may be left, asking first when it has some. */
export interface HoldsUnsavedChanges {
  canLeave(): boolean | Promise<boolean>;
}

/**
 * Leaving a page inside the console (a link, Back) asks the page first.
 *
 * Not when nobody is signed in any more. The session ended, or the person
 * signed out, and the page is on its way to the sign-in form whatever is
 * answered: "stay" would stay on a page that can do nothing, and "leave" would
 * give up what was typed for no reason. Nothing is asked and nothing is lost -
 * LocalizationDrafts keeps what both pages have not saved, and the same
 * administrator finds it in its boxes after signing in again.
 *
 * The page may be gone already (the admin layout stops drawing its pages the
 * moment there is no administrator): then there is nobody to ask.
 *
 * Closing the tab or reloading is the browser's to ask: LocalizationDrafts
 * listens for `beforeunload`.
 */
export const unsavedChangesGuard: CanDeactivateFn<HoldsUnsavedChanges | null> = (page) => {
  if (!inject(SessionService).signedIn()) {
    return true;
  }
  return page ? page.canLeave() : true;
};
