import { AnnouncementStatus, AnnouncementTemplate, AudienceCount, RecipientStatus } from '../../../core/api/models';
import { BadgeTone } from '../../../ui/badge/badge.component';

/** The four templates, as the composer offers them. */
export const TEMPLATES: { value: AnnouncementTemplate; label: string; hint: string }[] = [
  { value: 'newVersion', label: 'New version', hint: 'A site has a new version.' },
  { value: 'newRelease', label: 'New release', hint: 'A new anotoki site is out.' },
  { value: 'newSurvey', label: 'New survey', hint: 'A survey about a site, on the survey site.' },
  { value: 'blank', label: 'Blank', hint: 'Your own words from the start.' },
];

export function templateLabel(template: AnnouncementTemplate): string {
  return TEMPLATES.find((entry) => entry.value === template)?.label ?? template;
}

export const STATUS_LABELS: Record<AnnouncementStatus, { label: string; tone: BadgeTone }> = {
  sending: { label: 'Sending', tone: 'info' },
  sent: { label: 'Sent', tone: 'success' },
  stopped: { label: 'Stopped', tone: 'warning' },
};

export const RECIPIENT_LABELS: Record<RecipientStatus, { label: string; tone: BadgeTone }> = {
  pending: { label: 'Not sent yet', tone: 'neutral' },
  sent: { label: 'Sent', tone: 'success' },
  failed: { label: 'Failed', tone: 'danger' },
  skipped: { label: 'Skipped', tone: 'warning' },
};

/** Why people an audience names are left out, in the order the server counts them (each person once, for the first reason). */
export function leftOutText(count: AudienceCount): string[] {
  const reasons: [number, string, string][] = [
    [count.left_out.disabled, 'is switched off', 'are switched off'],
    [count.left_out.unconfirmed, 'has not confirmed the address', 'have not confirmed their address'],
    [count.left_out.waiting, 'is waiting for approval', 'are waiting for approval'],
    [count.left_out.news_off, 'has stopped the news', 'have stopped the news'],
  ];
  return reasons.filter(([people]) => people > 0).map(([people, one, more]) => `${people} ${people === 1 ? one : more}`);
}

/** "1 person", "12 people". */
export function people(count: number): string {
  return `${count} ${count === 1 ? 'person' : 'people'}`;
}
