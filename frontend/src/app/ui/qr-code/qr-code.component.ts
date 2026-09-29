import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { encodeQr } from './qr-encoder';

/**
 * The quiet zone, in modules.
 *
 * Four is what the standard asks for, and it is not decoration: a scanner
 * finds the symbol by its border, and a QR pressed up against other content is
 * a QR that often will not read.
 */
const QUIET_ZONE = 4;

interface Run {
  x: number;
  y: number;
  width: number;
}

/**
 * One QR code, as inline SVG. Ported from the genshin site.
 *
 * SVG rather than a canvas so it scales with the layout and prints properly,
 * and inline so there is no image request for something computed locally.
 * The dark modules are merged into horizontal runs rather than drawn one at a
 * time, which turns a few thousand rectangles into a few hundred.
 *
 * The one change from the original: the rectangles are bound in the template
 * instead of being assembled into a string for [innerHTML], so nothing has to
 * be marked as trusted to get past the sanitizer.
 *
 * Always dark on white, whatever the theme - a scanner expects that, and an
 * inverted code is one many will not read. A value too long to encode draws
 * nothing; the page shows the same secret as text beside it anyway.
 */
@Component({
  selector: 'app-qr-code',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './qr-code.component.html',
  styleUrl: './qr-code.component.scss',
})
export class QrCodeComponent {
  readonly value = input.required<string>();
  /** What a screen reader hears; the page puts the same secret next to it as text. */
  readonly label = input('QR code');

  protected readonly symbol = computed<{ size: number; runs: Run[] } | null>(() => {
    let modules: boolean[][];
    try {
      modules = encodeQr(this.value());
    } catch {
      return null;
    }

    const runs: Run[] = [];
    modules.forEach((row, y) => {
      let runStart: number | null = null;

      // One rectangle per run of dark modules, closed off at the row's end.
      for (let x = 0; x <= row.length; x++) {
        const dark = x < row.length && row[x];

        if (dark && runStart === null) {
          runStart = x;
        } else if (!dark && runStart !== null) {
          runs.push({ x: runStart + QUIET_ZONE, y: y + QUIET_ZONE, width: x - runStart });
          runStart = null;
        }
      }
    });

    return { size: modules.length + QUIET_ZONE * 2, runs };
  });
}
