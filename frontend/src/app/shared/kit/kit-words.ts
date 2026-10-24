import { AnotokiWordKey } from '@anotoki/lib/ui/config';
import { TranslationKey } from '../i18n/en';
import { TranslationService } from '../i18n/translation.service';

/** The part of a kit word's key before its first dot: `ui`, `topbar`, `language`, `theme`. */
type KitNamespace<K extends string> = K extends `${infer Namespace}.${string}` ? Namespace : never;
/** The rest of the kit's keys in one namespace: `close` for `ui.close`. */
type KitNames<Namespace extends string> = AnotokiWordKey extends infer K ? (K extends `${Namespace}.${infer Name}` ? Name : never) : never;
/** For a kit word, the IAM's key that says it - by namespace, as the kit's keys are. */
type KitWordKeys = { readonly [Namespace in KitNamespace<AnotokiWordKey>]?: { readonly [Name in KitNames<Namespace>]?: TranslationKey } };

/**
 * The kit's words (@anotoki/lib/ui: the bar, the dialogs, the fields, the
 * switches) that the IAM said in its own words before the kit was the
 * family's: for each, the IAM's key. Its string comes from the database - the
 * language on the page, English in the admin panel - so an administrator's
 * rewording on the Translations page is what the kit shows too, and a key the
 * bundle has no string for shows as the key, as on every page of the IAM.
 *
 * Written by namespace, not as the kit's dotted keys: those share `ui.` and
 * `theme.` with the IAM's own keys, and the backend's scan of the pages
 * (`php translations.php --status`, the library's KeyScanner) reads every
 * quoted `ui.something` as one of the IAM's - the keys here on the right are
 * the IAM's, and are read as such.
 *
 * A kit word not here reads the library's own key for it (`anotoki.<key>`,
 * shared/kit/kit.config.ts): the database's string - reworded on the
 * Translations page like any other -, else the kit's own words in English and
 * informal Slovak. So do the language switcher's (`anotoki.language.*`: its
 * name and its button's, which the IAM had as `lang.label` and `lang.button` -
 * migration 017 moved them, rewordings and all - and its notes), the
 * pagination's, the note under the theme switch when the account refuses a
 * theme, and what the IAM never shows ("Your anotoki account", "Editor").
 */
const IAM_KEYS: KitWordKeys = {
  ui: {
    close: 'ui.closeDialog',
    dismiss: 'ui.dismiss',
    notifications: 'ui.notifications',
    cancel: 'common.cancel',
    confirm: 'common.confirm',
    retry: 'common.retry',
    optional: 'ui.optional',
    loading: 'common.loading',
    showPassword: 'ui.showPassword',
    hidePassword: 'ui.hidePassword',
    copy: 'common.copy',
    copied: 'common.copied',
    copyToClipboard: 'ui.copyToClipboard',
    copiedToClipboard: 'ui.copiedToClipboard',
    copyFailed: 'ui.copyFailed',
    qrCode: 'ui.qrCode',
    or: 'common.or',
  },
  topbar: {
    skipToContent: 'shell.skipToContent',
    sections: 'shell.mainNav',
    phoneMenu: 'shell.menu',
    menu: 'shell.userMenu',
    roleAdmin: 'shell.roleAdmin',
    admin: 'shell.admin',
    signIn: 'common.signIn',
    signOut: 'shell.signOut',
    // The one item of the bar that waits here is Sign out, which saves
    // nothing: while it waits it keeps its own words, beside the spinner.
    saving: 'shell.signOut',
  },
  theme: {
    label: 'theme.label',
    light: 'theme.light',
    dark: 'theme.dark',
    auto: 'theme.auto',
    autoHint: 'theme.autoHint',
  },
};

/**
 * The IAM's string for a kit word, or null where the IAM has no key of its own
 * for it (the kit's `lookup` then reads the library's). Reads the
 * translations' signals, so whatever draws a kit word follows the language and
 * the strings as they come.
 */
export function iamKitWord(i18n: TranslationService, key: AnotokiWordKey): string | null {
  // The person's button: the IAM's words, then the name - "Account menu: anna".
  if (key === 'topbar.menuButton') {
    return `${i18n.t('shell.userMenu')}: {name}`;
  }
  const [namespace, name] = key.split('.') as [KitNamespace<AnotokiWordKey>, string];
  const iamKey = (IAM_KEYS[namespace] as Readonly<Record<string, TranslationKey>> | undefined)?.[name];
  return iamKey ? i18n.t(iamKey) : null;
}
