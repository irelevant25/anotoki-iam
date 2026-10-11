import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ConfirmHostComponent } from '@anotoki/lib/ui/dialog';
import { ToastHostComponent } from '@anotoki/lib/ui/toast';

/** The page, plus the two things drawn over any page: toasts and the confirm dialog (the family's kit). */
@Component({
  selector: 'app-root',
  imports: [RouterOutlet, ToastHostComponent, ConfirmHostComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './app.component.html',
  styleUrl: './app.component.scss',
})
export class AppComponent {}
