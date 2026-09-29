import { Injectable, signal } from '@angular/core';

export interface ConfirmOptions {
  title: string;
  message?: string;
  /** Defaults to "Confirm" in the language on screen. */
  confirmLabel?: string;
  cancelLabel?: string;
  /** `danger` for anything that ends, removes or cannot be undone. */
  tone?: 'primary' | 'danger';
}

interface PendingConfirm extends ConfirmOptions {
  resolve: (answer: boolean) => void;
}

/**
 * "Are you sure?", as a promise: `if (await confirm.ask({ … })) { … }`.
 *
 * One question at a time. Asking while another is open answers the first
 * with "no" - it can only have been left open by accident. The dialog itself
 * is drawn once, by ConfirmHostComponent in the app root.
 */
@Injectable({ providedIn: 'root' })
export class ConfirmService {
  private readonly _pending = signal<PendingConfirm | null>(null);

  readonly pending = this._pending.asReadonly();

  ask(options: ConfirmOptions): Promise<boolean> {
    this._pending()?.resolve(false);
    return new Promise<boolean>((resolve) => this._pending.set({ ...options, resolve }));
  }

  settle(answer: boolean): void {
    const pending = this._pending();
    if (pending) {
      this._pending.set(null);
      pending.resolve(answer);
    }
  }
}
