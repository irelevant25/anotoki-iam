import { DOCUMENT, Injectable, computed, effect, inject, signal } from '@angular/core';
import { readStorage, writeStorage } from '../util/storage';

export type ThemeMode = 'light' | 'dark' | 'auto';
export type ResolvedTheme = 'light' | 'dark';

/** Shared with public/theme-boot.js, which applies the same choice before the app has loaded. */
const THEME_KEY = 'anotoki-iam:theme';

/** The browser chrome colour, matched to each theme's page background. */
const THEME_COLOR: Record<ResolvedTheme, string> = { light: '#f3f6fb', dark: '#070d1c' };

/**
 * Light, dark, or whatever the operating system says.
 *
 * The resolved theme is written to `<html data-theme>`, which is what every
 * colour token in styles.scss keys on. `auto` keeps listening to the system
 * setting while the page is open, so switching the OS to dark at sunset turns
 * the page with it.
 *
 * The choice is per device, in localStorage - it is about the screen, not the
 * person - and public/theme-boot.js reads the same key before first paint, so
 * a dark choice never flashes light while the bundle loads.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly _document = inject(DOCUMENT);
  private readonly _media = globalThis.matchMedia?.('(prefers-color-scheme: dark)');

  private readonly _systemDark = signal(this._media?.matches ?? false);

  readonly mode = signal<ThemeMode>(storedMode());
  readonly resolved = computed<ResolvedTheme>(() => {
    const mode = this.mode();
    return mode === 'auto' ? (this._systemDark() ? 'dark' : 'light') : mode;
  });

  constructor() {
    this._media?.addEventListener('change', (event) => this._systemDark.set(event.matches));

    effect(() => {
      const theme = this.resolved();
      const root = this._document.documentElement;
      root.dataset['theme'] = theme;
      root.style.colorScheme = theme;
      this._document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLOR[theme]);
    });
  }

  set(mode: ThemeMode): void {
    this.mode.set(mode);
    writeStorage(THEME_KEY, mode === 'auto' ? null : mode);
  }
}

function storedMode(): ThemeMode {
  const value = readStorage(THEME_KEY);
  return value === 'light' || value === 'dark' ? value : 'auto';
}
