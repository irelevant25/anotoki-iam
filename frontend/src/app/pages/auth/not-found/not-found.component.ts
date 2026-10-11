import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AutofocusDirective, ButtonComponent, IconComponent } from '@anotoki/lib/ui';
import { TPipe } from '../../../core/i18n/t.pipe';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';

/** Any address the router does not know, inside the account and admin areas too. */
@Component({
  selector: 'app-not-found',
  imports: [RouterLink, TPipe, AuthCardComponent, AutofocusDirective, ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './not-found.component.html',
  styleUrl: './not-found.component.scss',
})
export class NotFoundComponent {}
