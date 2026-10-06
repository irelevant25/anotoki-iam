import { Pipe, PipeTransform, inject } from '@angular/core';
import { AccountPreferences } from '../api/models';
import { SessionService } from '../auth/session.service';
import { formatClock, formatDatePattern } from './date-patterns';
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
 *
 * A signed-in person's own date format and clock (the account's, which every
 * anotoki site follows) win over the language's: `2026-09-27 10:15 AM`.
 */
@Pipe({ name: 'appDate', pure: false })
export class AppDatePipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);
  private readonly _session = inject(SessionService);

  transform(value: string | null | undefined, style: DateStyle = 'datetime', fallback = ''): string {
    if (!value) {
      return fallback;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return value;
    }

    const locale = this._i18n.locale();
    const chosen = this._session.user()?.preferences ?? null;

    if (style === 'relative') {
      return formatRelative(date, locale) ?? dayOf(date, locale, chosen);
    }
    if (style === 'date') {
      return dayOf(date, locale, chosen);
    }
    if (style === 'time') {
      return timeOf(date, locale, chosen);
    }

    // Neither chosen: the language's own way of putting the two together.
    return chosen?.date_format || chosen?.time_format ? `${dayOf(date, locale, chosen)} ${timeOf(date, locale, chosen)}` : formatter(locale, 'datetime').format(date);
  }
}

function dayOf(date: Date, locale: string, chosen: AccountPreferences | null): string {
  return chosen?.date_format ? formatDatePattern(date, chosen.date_format) : formatter(locale, 'date').format(date);
}

function timeOf(date: Date, locale: string, chosen: AccountPreferences | null): string {
  return chosen?.time_format ? formatClock(date, locale, chosen.time_format) : formatter(locale, 'time').format(date);
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
