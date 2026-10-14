import { ChangeDetectionStrategy, Component } from '@angular/core';
import { LegalLinksComponent } from '@anotoki/lib/legal';

/**
 * The foot of the IAM's pages: the family's Terms · Privacy · Contact
 * (`<anotoki-legal-links>` - the legal pages' own paths, the page's language,
 * the database's words for the three). Under the account pages and the legal
 * pages (the family's frame, its `shellFooter`), and on the sign-in pages
 * (layouts/auth-layout), under the form. Not in the admin panel.
 */
@Component({
  selector: 'app-site-footer',
  imports: [LegalLinksComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './site-footer.component.html',
  styleUrl: './site-footer.component.scss',
})
export class SiteFooterComponent {}
