import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslationParams, TranslationService, WordKey } from './translation.service';

/**
 * `{{ 'login.title' | t }}`, and with placeholders: `{{ 'login.backTo' | t: { app: name } }}`.
 *
 * The IAM's own pipe over the family's TranslationService (the library's
 * `translate` pipe takes any string): the key is typed - the IAM's keys
 * (en.ts) and the library's words (`anotoki.*`) - so a key that does not exist
 * is a template compile error.
 *
 * Impure on purpose. A pure pipe caches against its argument, and the argument
 * is a constant key - switching language would change nothing on screen. The
 * signal read inside `t()` is what schedules the re-render; being impure is
 * what lets the pipe answer again when it happens. The cost is one dictionary
 * lookup per binding per change detection, which is nothing.
 */
@Pipe({ name: 't', pure: false })
export class TPipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);

  transform(key: WordKey, params?: TranslationParams): string {
    return this._i18n.t(key, params);
  }
}
