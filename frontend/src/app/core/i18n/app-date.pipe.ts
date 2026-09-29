import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslationService } from './translation.service';

export type DateStyle = 'date' | 'datetime' | 'time' | 'relative';

const FORMATS: Record<Exclude<DateStyle, 'relative'>, Intl.DateTimeFormatOptions> = {
  date: { day: 'numeric', month: 'short', year: 'numeric' },
  datetime: { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' },
  time: { hour: '2-digit', minute: '2-digit' },
};

const formatterCache = new Map<string, Intl.DateTimeFormat>();
const relativeCache = new Map<string, Intl.RelativeTimeFormat>();

/**
 * An ISO timestamp from the API, written the way the reader's language writes
 * dates - `27 Sept 2026, 10:15` or `27. 9. 2026 10:15`.
 *
 * The API sends UTC; the browser's own time zone is what a person expects to
 * read, and `Intl` applies it. `relative` says "3 hours ago" for recent things
 * and falls back to the date beyond a week, where "41 days ago" stops meaning
 * anything. Impure for the same reason as the `t` pipe: the language can
 * change under a constant argument.
 */
@Pipe({ name: 'appDate', pure: false })
export class AppDatePipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);

  transform(value: string | null | undefined, style: DateStyle = 'datetime', fallback = ''): string {
    if (!value) {
      return fallback;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return value;
    }

    const locale = this._i18n.locale();

    if (style === 'relative') {
      return formatRelative(date, locale) ?? formatter(locale, 'date').format(date);
    }

    return formatter(locale, style).format(date);
  }
}

function formatter(locale: string, style: Exclude<DateStyle, 'relative'>): Intl.DateTimeFormat {
  const key = `${locale}|${style}`;
  let format = formatterCache.get(key);
  if (!format) {
    format = new Intl.DateTimeFormat(locale, FORMATS[style]);
    formatterCache.set(key, format);
  }
  return format;
}

function formatRelative(date: Date, locale: string): string | null {
  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  const absolute = Math.abs(seconds);

  let format = relativeCache.get(locale);
  if (!format) {
    format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    relativeCache.set(locale, format);
  }

  if (absolute < 45) {
    return format.format(0, 'second');
  }
  if (absolute < 3600) {
    return format.format(Math.round(seconds / 60), 'minute');
  }
  if (absolute < 86400) {
    return format.format(Math.round(seconds / 3600), 'hour');
  }
  if (absolute < 7 * 86400) {
    return format.format(Math.round(seconds / 86400), 'day');
  }
  return null;
}
