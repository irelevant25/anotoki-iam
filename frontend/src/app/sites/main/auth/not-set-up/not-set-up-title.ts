import { inject } from '@angular/core';
import { ResolveFn } from '@angular/router';
import { SiteStatus } from '@anotoki/lib/migrations';
import { TranslationKey } from '../../../../shared/i18n/en';

/**
 * /not-set-up's title in the browser's tab, once the server has said where
 * the IAM stands (asked here, before the page is drawn): "Not set up yet" only
 * while the installer is offered - the IAM was never installed. One installed
 * whose configuration has gone missing is not called that; its page says it
 * is not available right now, and the tab "Something went wrong".
 *
 * A file of its own, so the routes need not load the page to name it.
 */
export const notSetUpTitle: ResolveFn<TranslationKey> = async () => {
  const status = inject(SiteStatus);
  const state = await status.check();

  return state === 'not-set-up' && !status.installed() ? 'notSetUp.pageTitle' : 'errorPage.pageTitle';
};
