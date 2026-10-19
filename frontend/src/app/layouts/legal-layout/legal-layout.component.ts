import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { ANOTOKI_LEGAL_CONFIG } from '@anotoki/lib/legal';
import { ShellComponent } from '@anotoki/lib/shell';
import { Changelog } from '../../core/changelog/changelog.service';
import { iamLegalPagesConfig } from '../../core/legal/legal-facts';
import { AppTopBarComponent } from '../top-bar/top-bar.component';

/**
 * The legal pages' frame - Terms, Privacy, Contact, the family's pages
 * (@anotoki/lib/legal/pages): the family's frame as the account pages have
 * it (the skip link, the bar, `<main>`) and the family's footer under it
 * (@anotoki/lib/footer: the IAM's version, What's new and Feedback, then
 * Terms · Privacy · Contact) - and the IAM's facts, which only these pages
 * read, given to them here (core/legal/legal-facts.ts), out of the first
 * load. The Contact page opens the feedback form too.
 *
 * Anybody may read them - every anotoki site links to the IAM's Privacy page
 * - so nothing here needs a session. The bar is the account pages' for
 * somebody signed in and Sign in for anybody else (`area="public"`); the
 * session is asked for before the first page when a legal page is the one
 * the site was opened at (startTranslations()), and read as it is otherwise.
 */
@Component({
  selector: 'app-legal-layout',
  imports: [RouterOutlet, ShellComponent, AppTopBarComponent, SiteFooterComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: iamLegalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './legal-layout.component.html',
  styleUrl: './legal-layout.component.scss',
})
export class LegalLayoutComponent {
  /** The footer's version and What's new: the list the start asked for (app.config.ts). */
  protected readonly changelog = inject(Changelog);
}
