import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { TPipe } from '../../core/i18n/t.pipe';
import { IconComponent, IconName } from '../../ui/icon/icon.component';
import { TopBarComponent } from '../top-bar/top-bar.component';

export interface NavItem {
  link: string;
  label: string;
  icon: IconName;
  /** Active only on this exact path - for the area's own home, which every other path starts with. */
  exact?: boolean;
}

/**
 * The frame of the account pages and the admin panel: the anotoki family's
 * top bar (layouts/top-bar - the brand, the account pages as links, the
 * language, the theme, the person's menu) and the page under it.
 *
 * The admin panel adds its own section navigation (`nav`): a side list
 * beside the page, a scrolling strip of links under the bar on a narrow
 * screen. It is English, like the panel; the account pages have none - their
 * sections are the bar's links.
 */
@Component({
  selector: 'app-shell',
  imports: [RouterLink, RouterLinkActive, TPipe, IconComponent, TopBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss',
})
export class ShellComponent {
  readonly area = input.required<'account' | 'admin'>();
  /** The admin panel's sections, beside the page; none on the account pages. */
  readonly nav = input<readonly NavItem[]>([]);
}
