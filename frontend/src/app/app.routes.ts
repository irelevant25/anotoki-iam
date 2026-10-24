import { Routes } from '@angular/router';
import { sessionGuard } from './shared/auth/guards';
import { MAIN_ROUTES } from './sites/main/main.routes';

/**
 * Four areas, each loaded only when it is visited - each with the anotoki
 * family's footer (@anotoki/lib/footer) under its pages:
 *
 *   the sign-in pages   no session needed; bilingual; the night-sky layout
 *   /account            the signed-in account; bilingual; the app shell
 *   /admin              IAM administrators; English; the app shell
 *   the legal pages     anybody's; bilingual; the app shell - /terms,
 *                       /privacy (the one every anotoki site links to for the
 *                       account) and /contact, the family's pages
 *                       (@anotoki/lib/legal/pages), with the IAM's facts
 *                       (sites/main/legal/legal-facts.ts)
 *
 * - and /not-set-up, the family's status page, which is a page of its own:
 * on no layout, so with no bar, no controls and no footer round it (the
 * owner's rule of 2026-10-18; the IAM has no gate to draw it - it never
 * closes while an update waits).
 *
 * In two halves, as on every anotoki site: the admin panel
 * (sites/admin/admin.routes.ts, loaded with its first page) and the rest
 * (sites/main/main.routes.ts, the sign-in pages, /account, the legal pages
 * and /not-set-up - in the first load, as they always were). /admin comes
 * first: the sign-in layout, last of the rest, has a not-found child (`**`)
 * that would otherwise swallow it. A URL under /admin that none of its pages
 * match falls through to that same page.
 *
 * Titles are translation keys on the translated pages and plain English in
 * the admin panel; IamTitleStrategy takes either.
 */
export const routes: Routes = [
  {
    path: 'admin',
    canActivate: [sessionGuard],
    loadChildren: () => import('./sites/admin/admin.routes').then((m) => m.ADMIN_ROUTES),
  },
  ...MAIN_ROUTES,
];
