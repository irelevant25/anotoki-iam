/**
 * The admin panel's moments, for the family's pages that take a writer of
 * their own - the admin tools' `formatDate` (shared/admin/admin-tools.config.ts)
 * and the Migrations page's (shared/migrations/migrations.config.ts): the
 * family's dates (@anotoki/lib/ui/dates, as the `anotokiDate` pipe writes
 * `'datetime'`: the page's locale - English in the panel -, the account's date
 * order and clock where it chose them), handed over by the admin panel's
 * routes as they load (sites/admin/admin.routes.ts). Those pages exist only
 * inside the panel, so the writer is always there when they draw - and the
 * dates' code comes with the panel's chunk, never with a sign-in page's first
 * load, which both configurations are part of.
 */
let writer: ((iso: string) => string) | null = null;

/** From the admin panel's routes (their environment initializer), before any of its pages can be made; null: none. */
export function useAdminDates(write: ((iso: string) => string) | null): void {
  writer = write;
}

/** A moment as the admin panel writes it; as it came, should no panel have handed its writer over. */
export function adminDateTime(iso: string): string {
  return writer ? writer(iso) : iso;
}
