import { DateFormatChoice, TimeFormatChoice } from '../api/models';

/**
 * The account's date formats and clock (migration 015) - the anotoki family's
 * one vocabulary, the genshin site's before it: a date written in a fixed
 * order of numbers, and the clock with or without am/pm. "Automatic" (null) is
 * the page language's own way, which the callers keep.
 */

export const DATE_FORMATS: readonly DateFormatChoice[] = ['dmy_dot', 'dmy_slash', 'mdy_slash', 'ymd_dash'];

export const TIME_FORMATS: readonly TimeFormatChoice[] = ['24', '12'];

/** A date in one of the fixed orders, in the browser's time zone: 6.10.2026, 06/10/2026, 10/06/2026, 2026-10-06. */
export function formatDatePattern(date: Date, format: DateFormatChoice): string {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();
  const pad = (value: number): string => String(value).padStart(2, '0');

  switch (format) {
    case 'dmy_dot':
      return `${day}.${month}.${year}`;
    case 'dmy_slash':
      return `${pad(day)}/${pad(month)}/${year}`;
    case 'mdy_slash':
      return `${pad(month)}/${pad(day)}/${year}`;
    case 'ymd_dash':
      return `${year}-${pad(month)}-${pad(day)}`;
  }
}

const clockCache = new Map<string, Intl.DateTimeFormat>();

/** The time of day on the chosen clock, in the page language's words for am and pm: 14:30, or 2:30 PM. */
export function formatClock(date: Date, locale: string, clock: TimeFormatChoice): string {
  const key = `${locale}|${clock}`;
  let format = clockCache.get(key);
  if (!format) {
    format = new Intl.DateTimeFormat(locale, clock === '12' ? { hour: 'numeric', minute: '2-digit', hourCycle: 'h12' } : { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    clockCache.set(key, format);
  }
  return format.format(date);
}
