import { ChangeDetectionStrategy, Component, input, model } from '@angular/core';
import { FilterApplication, RoleGrant } from '../../../core/api/models';
import { CheckboxComponent } from '../../../ui/checkbox/checkbox.component';

/** A grant as one string, `client_id:ROLE`, so a set of them compares and toggles simply. */
export function grantKey(grant: RoleGrant): string {
  return `${grant.client_id}:${grant.role}`;
}

export function grantFromKey(key: string): RoleGrant {
  const index = key.indexOf(':');
  return { client_id: key.slice(0, index), role: key.slice(index + 1) };
}

/**
 * Every application's roles as checkboxes, one group per application.
 *
 * `selected` is the complete set of grants, which is what the backend takes:
 * anything unticked here is taken away when the set is saved.
 */
@Component({
  selector: 'app-role-picker',
  imports: [CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './role-picker.component.html',
  styleUrl: './role-picker.component.scss',
})
export class RolePickerComponent {
  readonly applications = input.required<FilterApplication[]>();
  readonly selected = model<string[]>([]);
  readonly disabled = input(false);

  protected isSelected(clientId: string, role: string): boolean {
    return this.selected().includes(grantKey({ client_id: clientId, role }));
  }

  protected toggle(clientId: string, role: string, on: boolean): void {
    const key = grantKey({ client_id: clientId, role });
    const rest = this.selected().filter((existing) => existing !== key);
    this.selected.set(on ? [...rest, key] : rest);
  }
}
