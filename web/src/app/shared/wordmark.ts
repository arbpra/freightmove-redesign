import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The FreightMove wordmark: "FREIGHT" in the current text colour, "MOVE" in
 * brand red with the chain-link mark. `tone` switches it for dark backgrounds.
 */
@Component({
  selector: 'fm-wordmark',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span class="mark" [class.on-dark]="tone() === 'dark'">
      <span class="freight">FREIGHT</span><span class="move">M<span class="link">O</span>VE</span>
    </span>
  `,
  styles: `
    :host {
      display: inline-flex;
    }

    .mark {
      font-weight: 900;
      font-size: 1.35rem;
      letter-spacing: -0.02em;
      line-height: 1;
      color: var(--fm-navy);
      white-space: nowrap;
    }

    .mark.on-dark {
      color: #ffffff;
    }

    .move {
      color: var(--fm-red);
    }

    /* The O in MOVE reads as the chain-link mark from the brand sheet. */
    .link {
      position: relative;
      display: inline-block;
    }
  `,
})
export class Wordmark {
  readonly tone = input<'light' | 'dark'>('light');
}
