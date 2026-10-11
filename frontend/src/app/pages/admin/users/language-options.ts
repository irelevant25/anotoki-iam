import { SiteLanguage } from '@anotoki/lib/translations';
import { SelectOption } from '@anotoki/lib/ui/forms';

/**
 * What the language select of the create-user and edit-user dialogs offers:
 * the languages on offer, under their English names (the panel is English).
 *
 * `stored` is the language the account has now. When it is no longer offered
 * it is listed all the same - last, by its code - so opening the dialog and
 * saving something else does not quietly change it; the server refuses a
 * language that is not offered only when it is sent.
 *
 * With no language known - the words have not arrived, as between an upload
 * and "Apply pending" - English is what there is: the one language the server
 * accepts whatever state its tables are in.
 */
export function languageOptions(offered: readonly SiteLanguage[], stored?: string): SelectOption[] {
  const options: SelectOption[] = offered.length ? offered.map((language) => ({ value: language.code, label: language.name })) : [{ value: 'en', label: 'English' }];
  if (stored && !options.some((option) => option.value === stored)) {
    options.push({ value: stored, label: stored.toUpperCase() });
  }
  return options;
}
