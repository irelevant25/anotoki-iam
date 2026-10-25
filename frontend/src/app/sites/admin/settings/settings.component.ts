import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { SelectComponent, SwitchComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { Setting } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';

/** What each switch does, said plainly - the setting's name alone does not say what happens when it is off. */
const DESCRIPTIONS: Record<string, { label: string; text: string }> = {
  login_enabled: {
    label: 'Signing in',
    text: 'Off: nobody but IAM admins can sign in to anything - not to the account pages, not to any site. Admins are let in so they can switch it back on.',
  },
  registration_enabled: {
    label: 'New accounts',
    text: 'Off: nobody can make an account, with the form or with Google. Existing accounts are not affected.',
  },
  registration_approval: {
    label: 'New accounts need approval',
    text:
      'On: an account made with the form or with Google cannot sign in anywhere until an administrator approves it under Approvals - its owner is emailed then. ' +
      'Accounts made here, and the accounts there are, are not affected. Switching it off lets in everybody still waiting.',
  },
  google_login_enabled: {
    label: 'Signing in with Google',
    text: 'Off: the Google button disappears and Google sign-ins are refused. Accounts made through Google can still sign in with an emailed code.',
  },
};

/**
 * /admin/settings - the switches an admin flips without a deploy.
 *
 * One save for the page, carrying only what changed: the backend checks every
 * value before it writes any, so half a form is never saved. Values travel as
 * text, as the settings table keeps them - a switch is "true" or "false".
 */
@Component({
  selector: 'app-settings',
  imports: [AnotokiDatePipe, AlertComponent, ButtonComponent, CardComponent, ErrorStateComponent, PageHeaderComponent, SelectComponent, SpinnerComponent, SwitchComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.scss',
})
export class SettingsComponent {
  private readonly _adminApi = inject(AdminApi);
  private readonly _toast = inject(ToastService);

  protected readonly settings = signal<Setting[] | null>(null);
  protected readonly edits = signal<Record<string, string>>({});
  protected readonly loadError = signal<string | null>(null);
  protected readonly saveError = signal<string | null>(null);
  protected readonly saving = signal(false);

  protected readonly groups = computed(() => {
    const groups = new Map<string, Setting[]>();
    for (const setting of this.settings() ?? []) {
      groups.set(setting.group_name, [...(groups.get(setting.group_name) ?? []), setting]);
    }
    return [...groups].map(([name, settings]) => ({ name, settings }));
  });

  protected readonly changes = computed(() => (this.settings() ?? []).filter((setting) => this.changed(setting)).map((setting) => ({ name: setting.name, value: this.value(setting) })));

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this._adminApi.settings().subscribe({
      next: (response) => {
        this.settings.set(response.settings);
        this.edits.set({});
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected value(setting: Setting): string {
    return this.edits()[setting.name] ?? String(setting.value);
  }

  protected changed(setting: Setting): boolean {
    const edit = this.edits()[setting.name];
    return edit !== undefined && edit !== String(setting.value);
  }

  protected edit(setting: Setting, value: string): void {
    this.edits.update((edits) => ({ ...edits, [setting.name]: value }));
  }

  protected discard(): void {
    this.edits.set({});
    this.saveError.set(null);
  }

  protected save(): void {
    const changes = this.changes();
    if (changes.length === 0) {
      return;
    }
    this.saveError.set(null);
    this.saving.set(true);
    this._adminApi.saveSettings(changes).subscribe({
      next: (response) => {
        this.saving.set(false);
        this.settings.set(response.settings);
        this.edits.set({});
        this._toast.success(changes.length === 1 ? 'The setting is saved.' : `${changes.length} settings are saved.`);
      },
      error: (error: ApiError) => {
        this.saving.set(false);
        this.saveError.set(adminErrorText(error));
      },
    });
  }

  protected describe(setting: Setting): { label: string; text: string } {
    return DESCRIPTIONS[setting.name] ?? { label: setting.name, text: '' };
  }

  protected options(setting: Setting): { value: string; label: string }[] {
    return (setting.options ?? []).map((option) => ({ value: option, label: option }));
  }

  protected groupTitle(name: string): string {
    return name === 'access' ? 'Who can get in' : name.charAt(0).toUpperCase() + name.slice(1);
  }
}
