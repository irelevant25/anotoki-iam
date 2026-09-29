import { Injectable, signal } from '@angular/core';

export type ToastTone = 'success' | 'info' | 'warning' | 'danger';

export interface Toast {
  id: number;
  message: string;
  tone: ToastTone;
}

/** How many stay on screen at once; older ones make way. */
const MAX_TOASTS = 4;

/**
 * Short confirmations of something that just happened - "Password changed." -
 * drawn by ToastHostComponent in a polite live region.
 *
 * For outcomes only. Anything the person has to act on, or read carefully,
 * belongs inline on the page, where it does not vanish after a few seconds.
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private _next = 0;
  private readonly _toasts = signal<Toast[]>([]);

  readonly toasts = this._toasts.asReadonly();

  show(message: string, tone: ToastTone = 'info', duration = 5000): void {
    const id = ++this._next;
    this._toasts.update((toasts) => [...toasts, { id, message, tone }].slice(-MAX_TOASTS));
    if (duration > 0) {
      setTimeout(() => this.dismiss(id), duration);
    }
  }

  success(message: string): void {
    this.show(message, 'success');
  }

  info(message: string): void {
    this.show(message, 'info');
  }

  warning(message: string): void {
    this.show(message, 'warning', 8000);
  }

  /** Errors stay longer: they are more likely to need reading twice. */
  error(message: string): void {
    this.show(message, 'danger', 9000);
  }

  dismiss(id: number): void {
    this._toasts.update((toasts) => toasts.filter((toast) => toast.id !== id));
  }
}
