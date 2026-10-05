import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ShellComponent } from '../../layouts/shell/shell.component';

/** The account area: the shell, whose top bar has the account pages as its links. */
@Component({
  selector: 'app-account-layout',
  imports: [RouterOutlet, ShellComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './account-layout.component.html',
  styleUrl: './account-layout.component.scss',
})
export class AccountLayoutComponent {}
