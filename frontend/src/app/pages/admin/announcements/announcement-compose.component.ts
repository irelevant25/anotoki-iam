import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Router, RouterLink } from '@angular/router';
import { AlertComponent, ButtonComponent, CardComponent, ErrorStateComponent, IconComponent, PageHeaderComponent, SpinnerComponent } from '@anotoki/lib/ui';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { CheckboxComponent, RadioGroupComponent, RadioOption, SelectComponent, SelectOption, SwitchComponent, TextareaComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { ToastService } from '@anotoki/lib/ui/toast';
import { catchError, debounceTime, distinctUntilChanged, filter, map, of, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import {
  AdminUser,
  AnnouncementAudience,
  AnnouncementCompose,
  AnnouncementContent,
  AnnouncementDraft,
  AnnouncementPreview,
  AnnouncementTemplate,
  AnnouncementVersion,
  AudienceCount,
  RoleGrant,
} from '../../../core/api/models';
import { adminErrorText } from '../admin-errors';
import { HoldsUnsavedChanges } from '../localization/unsaved-changes.guard';
import { TEMPLATES, leftOutText, people } from './announcement-labels';

type Part = keyof AnnouncementVersion;

const PARTS: Part[] = ['subject', 'heading', 'body', 'button'];
const EMPTY: AnnouncementVersion = { subject: '', heading: '', body: '', button: '' };
const ENGLISH = 'en';

/** Nothing in it: no version, and its readers get the English one. */
function isEmpty(version: AnnouncementVersion | undefined): boolean {
  return !version || PARTS.every((part) => version[part].trim() === '');
}

/** A template's words with the site's name where `{project}` stands. */
function withProject(version: AnnouncementVersion, project: string): AnnouncementVersion {
  const fill = (text: string): string => text.split('{project}').join(project);
  return { subject: fill(version.subject), heading: fill(version.heading), body: fill(version.body), button: fill(version.button) };
}

interface Person {
  id: number;
  username: string;
  email: string;
}

/**
 * /admin/announcements/new - an announcement, from a template to Send.
 *
 * A template fills the words in every language on offer (the mail strings of
 * the Translations page, the chosen site's name for `{project}`); they are
 * changed here, a language at a time. A language left empty has no version,
 * and its readers get the English one. Choosing another template or site
 * fills the words again - asking first once they were changed by hand.
 *
 * The preview is the server's: the mail exactly as a reader of the language
 * on screen gets it, drawn in a sandboxed frame, a moment after the words
 * stop changing - with the server's sentence in its place while the draft is
 * not one it would send (a link off the anotoki sites, a half-written
 * version). A test goes to the administrator's own address.
 *
 * Who it is for: everybody, the holders of some roles, some people - the
 * count says how many it reaches, and why the others are left out. Send makes
 * the announcement and its list of people, and its own page sends it.
 */
@Component({
  selector: 'app-announcement-compose',
  imports: [
    RouterLink,
    AlertComponent,
    ButtonComponent,
    CardComponent,
    CheckboxComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    RadioGroupComponent,
    SelectComponent,
    SpinnerComponent,
    SwitchComponent,
    TabsComponent,
    TextFieldComponent,
    TextareaComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(window:beforeunload)': 'onBeforeUnload($event)' },
  templateUrl: './announcement-compose.component.html',
  styleUrl: './announcement-compose.component.scss',
})
export class AnnouncementComposeComponent implements HoldsUnsavedChanges {
  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);
  private readonly _sanitizer = inject(DomSanitizer);

  protected readonly compose = signal<AnnouncementCompose | null>(null);
  protected readonly loadError = signal<string | null>(null);

  protected readonly template = signal<AnnouncementTemplate>('newVersion');
  protected readonly clientId = signal('');
  protected readonly link = signal('');
  protected readonly sender = signal('');
  protected readonly versions = signal<AnnouncementContent>({});
  protected readonly language = signal(ENGLISH);

  /** Changed by hand since the template last filled them: another template or site asks before it replaces them. */
  private readonly _wordsEdited = signal(false);
  private readonly _linkEdited = signal(false);

  protected readonly everybody = signal(false);
  protected readonly roles = signal<RoleGrant[]>([]);
  protected readonly chosen = signal<Person[]>([]);
  protected readonly search = signal('');
  protected readonly found = signal<AdminUser[]>([]);
  protected readonly count = signal<AudienceCount | null>(null);
  protected readonly countError = signal<string | null>(null);

  protected readonly preview = signal<AnnouncementPreview | null>(null);
  protected readonly previewError = signal<string | null>(null);
  protected readonly previewing = signal(false);
  protected readonly plainText = signal(false);
  protected readonly testing = signal(false);

  protected readonly sending = signal(false);
  protected readonly sendError = signal<string | null>(null);
  /** Made: the page is left for the announcement's own, with nothing to ask. */
  private _sent = false;

  protected readonly templateOptions: RadioOption[] = TEMPLATES;
  protected readonly people = people;
  protected readonly leftOutText = leftOutText;
  /** What announcements may use of the host's hourly limit - the server's ANNOUNCEMENT_HOURLY_SHARE. */
  protected readonly hourlyShare = (limit: number): number => Math.max(1, Math.floor(limit * 0.9));

  protected readonly application = computed(() => this.compose()?.applications.find((application) => application.client_id === this.clientId()) ?? null);

  protected readonly siteOptions = computed<SelectOption[]>(() => {
    const sites = (this.compose()?.applications ?? []).map((application) => ({ value: application.client_id, label: application.name + (application.enabled ? '' : ' (switched off)') }));
    return this.template() === 'blank' ? [{ value: '', label: 'No site' }, ...sites] : sites;
  });

  protected readonly senderOptions = computed<SelectOption[]>(() =>
    (this.compose()?.senders ?? []).map((sender) => ({ value: sender.id, label: sender.name ? `${sender.name} <${sender.address}>` : sender.address })),
  );

  protected readonly languageName = computed(() => {
    const names = new Map((this.compose()?.languages ?? []).map((language) => [language.code, language.native_name]));
    return (code: string): string => names.get(code) ?? code.toUpperCase();
  });

  protected readonly tabs = computed<TabItem[]>(() =>
    (this.compose()?.languages ?? []).map((language) => ({
      id: language.code,
      label: language.native_name + (language.code !== ENGLISH && isEmpty(this.versions()[language.code]) ? ' (English)' : ''),
    })),
  );

  protected readonly version = computed(() => this.versions()[this.language()] ?? EMPTY);
  protected readonly versionEmpty = computed(() => isEmpty(this.versions()[this.language()]));

  /** English always - the server says what it lacks - and every other language that has words. */
  protected readonly draft = computed<AnnouncementDraft>(() => {
    const content: AnnouncementContent = {};
    for (const [code, version] of Object.entries(this.versions())) {
      if (code === ENGLISH || !isEmpty(version)) {
        content[code] = version;
      }
    }
    return { template: this.template(), client_id: this.clientId(), link: this.link().trim(), sender: this.sender(), content };
  });

  protected readonly audience = computed<AnnouncementAudience>(() => ({
    everybody: this.everybody(),
    roles: this.everybody() ? [] : this.roles(),
    user_ids: this.everybody() ? [] : this.chosen().map((person) => person.id),
  }));

  protected readonly audienceChosen = computed(() => {
    const audience = this.audience();
    return audience.everybody || audience.roles.length > 0 || audience.user_ids.length > 0;
  });

  /** The languages the people it reaches read, and which version each gets. */
  protected readonly readers = computed(() =>
    Object.entries(this.count()?.languages ?? {}).map(([code, readers]) => ({
      code,
      readers,
      name: this.languageName()(code),
      english: code !== ENGLISH && isEmpty(this.versions()[code]),
    })),
  );

  /**
   * Drawn in a frame with `sandbox=""` - no script, no form, an origin of its
   * own - so the mail's HTML cannot reach the panel; Angular's sanitizer would
   * take the inline styles a mail is drawn with. The HTML is the server's,
   * every word in it escaped (announcementMessage()).
   */
  protected readonly previewHtml = computed<SafeHtml | null>(() => {
    const html = this.preview()?.html;
    return html ? this._sanitizer.bypassSecurityTrustHtml(html) : null;
  });

  constructor() {
    this._adminApi.announcementCompose().subscribe({
      next: (compose) => this._start(compose),
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });

    toObservable(computed(() => ({ draft: this.draft(), language: this.language(), ready: this.compose() !== null })))
      .pipe(
        filter((state) => state.ready),
        map(({ draft, language }) => ({ draft, language })),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        debounceTime(500),
        tap(() => this.previewing.set(true)),
        switchMap(({ draft, language }) =>
          this._adminApi.previewAnnouncement(draft, language).pipe(
            map((preview) => ({ preview, error: null as string | null })),
            catchError((error: ApiError) => of({ preview: null, error: adminErrorText(error) })),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe(({ preview, error }) => {
        this.previewing.set(false);
        this.previewError.set(error);
        if (preview) {
          this.preview.set(preview);
        }
      });

    toObservable(this.audience)
      .pipe(
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        debounceTime(300),
        switchMap((audience) =>
          !audience.everybody && audience.roles.length === 0 && audience.user_ids.length === 0
            ? of({ count: null, error: null })
            : this._adminApi.announcementAudience(audience).pipe(
                map((count) => ({ count, error: null as string | null })),
                catchError((error: ApiError) => of({ count: null, error: adminErrorText(error) })),
              ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe(({ count, error }) => {
        this.count.set(count);
        this.countError.set(error);
      });

    toObservable(this.search)
      .pipe(
        map((term) => term.trim()),
        distinctUntilChanged(),
        debounceTime(300),
        switchMap((term) => (term.length < 2 ? of([]) : this._adminApi.users({ search: term }).pipe(catchError(() => of([] as AdminUser[]))))),
        takeUntilDestroyed(),
      )
      .subscribe((users) => this.found.set(users.slice(0, 8)));
  }

  // ── The mail ───────────────────────────────────────────────────────────────

  protected chooseTemplate(template: string): void {
    const previous = this.template();
    this.template.set(template as AnnouncementTemplate);
    // A site is needed by every template but blank: going back from blank, the first one.
    if (template !== 'blank' && !this.clientId()) {
      this.clientId.set(this._firstSite());
    }
    if (template !== previous) {
      void this._refill();
    }
  }

  protected chooseSite(clientId: string): void {
    if (clientId !== this.clientId()) {
      this.clientId.set(clientId);
      void this._refill();
    }
  }

  protected setLink(link: string): void {
    this.link.set(link);
    this._linkEdited.set(true);
  }

  // ── The words ──────────────────────────────────────────────────────────────

  protected setPart(part: Part, value: string): void {
    const code = this.language();
    this.versions.update((versions) => ({ ...versions, [code]: { ...(versions[code] ?? EMPTY), [part]: value } }));
    this._wordsEdited.set(true);
  }

  /** This language gets no version of its own: its readers get the English one. */
  protected clearVersion(): void {
    const code = this.language();
    this.versions.update((versions) => ({ ...versions, [code]: { ...EMPTY } }));
    this._wordsEdited.set(true);
  }

  /** This language's words again from the template - the others stay as they are. */
  protected refillVersion(): void {
    const code = this.language();
    this.versions.update((versions) => ({ ...versions, [code]: this._templateWords(code) }));
  }

  // ── Who it is for ──────────────────────────────────────────────────────────

  protected roleChosen(clientId: string, role: string): boolean {
    return this.roles().some((grant) => grant.client_id === clientId && grant.role === role);
  }

  protected toggleRole(clientId: string, role: string, on: boolean): void {
    this.roles.update((roles) => (on ? [...roles, { client_id: clientId, role }] : roles.filter((grant) => grant.client_id !== clientId || grant.role !== role)));
  }

  protected addPerson(user: AdminUser): void {
    if (!this.chosen().some((person) => person.id === user.id)) {
      this.chosen.update((chosen) => [...chosen, { id: user.id, username: user.username, email: user.email }]);
    }
    this.search.set('');
    this.found.set([]);
  }

  protected removePerson(id: number): void {
    this.chosen.update((chosen) => chosen.filter((person) => person.id !== id));
  }

  protected isChosen(id: number): boolean {
    return this.chosen().some((person) => person.id === id);
  }

  // ── Test and send ──────────────────────────────────────────────────────────

  protected sendTest(): void {
    this.testing.set(true);
    this._adminApi.testAnnouncement(this.draft(), this.language()).subscribe({
      next: (result) => {
        this.testing.set(false);
        if (result.sent) {
          this._toast.success(`A test is on its way to ${result.email}.`);
        } else {
          this._toast.error('The test could not be sent - the Email log says more.');
        }
      },
      error: (error: ApiError) => {
        this.testing.set(false);
        this._toast.error(adminErrorText(error));
      },
    });
  }

  protected async send(): Promise<void> {
    this.sendError.set(null);
    if (!this.audienceChosen()) {
      this.sendError.set('Choose who it is for: everybody, some roles, or some people.');
      return;
    }

    const sender = this.compose()?.senders.find((entry) => entry.id === this.sender());
    const reach = this.count()?.recipients;
    const sure = await this._confirm.ask({
      title: reach === undefined ? 'Send the announcement?' : `Send it to ${people(reach)}?`,
      message:
        (sender ? `From ${sender.name ? `${sender.name} <${sender.address}>` : sender.address}. ` : '') +
        'It goes out in batches while its page is open, and can be stopped there - a mail that went cannot be taken back.',
      confirmLabel: 'Send',
    });
    if (!sure) {
      return;
    }

    this.sending.set(true);
    this._adminApi.createAnnouncement(this.draft(), this.audience()).subscribe({
      next: (announcement) => {
        this._sent = true;
        void this._router.navigate(['/admin/announcements', announcement.id], { queryParams: { send: 1 } });
      },
      error: (error: ApiError) => {
        this.sending.set(false);
        this.sendError.set(adminErrorText(error));
      },
    });
  }

  // ── Leaving ────────────────────────────────────────────────────────────────

  canLeave(): boolean | Promise<boolean> {
    if (!this._unsent()) {
      return true;
    }
    return this._confirm.ask({
      title: 'Leave without sending?',
      message: 'What you wrote here is not kept.',
      confirmLabel: 'Leave',
      tone: 'danger',
    });
  }

  protected onBeforeUnload(event: BeforeUnloadEvent): void {
    if (this._unsent()) {
      event.preventDefault();
    }
  }

  private _unsent(): boolean {
    return !this._sent && (this._wordsEdited() || this._linkEdited() || this.audienceChosen());
  }

  // ── Filling ────────────────────────────────────────────────────────────────

  private _start(compose: AnnouncementCompose): void {
    this.compose.set(compose);
    this.sender.set(compose.senders.find((sender) => sender.id === 'noreply')?.id ?? compose.senders[0]?.id ?? '');
    this.clientId.set(this._firstSite());
    this._fill();
  }

  /** The first site that is switched on and not the IAM itself, as a starting point. */
  private _firstSite(): string {
    const applications = this.compose()?.applications ?? [];
    return (applications.find((application) => application.enabled && !application.system) ?? applications[0])?.client_id ?? '';
  }

  /** Another template or site: the words again from it - asked first once they were changed by hand - and the link, unless it was typed. */
  private async _refill(): Promise<void> {
    if (!this._linkEdited()) {
      this.link.set(this._templateLink());
    }
    if (this._wordsEdited()) {
      const sure = await this._confirm.ask({
        title: 'Start the words again from the template?',
        message: 'What you wrote, in every language, is replaced by the words of the template for this site. Keep them, and change them yourself instead.',
        confirmLabel: 'Replace the words',
        cancelLabel: 'Keep my words',
      });
      if (!sure) {
        return;
      }
    }
    this._fill();
  }

  private _fill(): void {
    const versions: AnnouncementContent = {};
    for (const language of this.compose()?.languages ?? []) {
      versions[language.code] = this._templateWords(language.code);
    }
    this.versions.set(versions);
    this._wordsEdited.set(false);
    if (!this._linkEdited()) {
      this.link.set(this._templateLink());
    }
  }

  private _templateWords(code: string): AnnouncementVersion {
    const words = this.compose()?.templates[this.template()]?.[code];
    return words ? withProject(words, this.application()?.name ?? '') : { ...EMPTY };
  }

  /** A survey is on the survey site; anything else leads to the site it is about. */
  private _templateLink(): string {
    const applications = this.compose()?.applications ?? [];
    const site = this.template() === 'newSurvey' ? applications.find((application) => application.client_id === 'survey') : this.application();
    return site?.home_url ?? '';
  }
}
