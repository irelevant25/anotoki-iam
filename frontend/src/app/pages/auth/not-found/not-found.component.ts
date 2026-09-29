import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TPipe } from '../../../core/i18n/t.pipe';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { AutofocusDirective } from '../../../ui/autofocus.directive';
import { ButtonComponent } from '../../../ui/button/button.component';
import { IconComponent } from '../../../ui/icon/icon.component';

/** Any address the router does not know, inside the account and admin areas too. */
@Component({
  selector: 'app-not-found',
  imports: [RouterLink, TPipe, AuthCardComponent, AutofocusDirective, ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './not-found.component.html',
  styleUrl: './not-found.component.scss',
})
export class NotFoundComponent {}
