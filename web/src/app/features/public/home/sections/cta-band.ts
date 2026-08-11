import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { Reveal } from '../../../../shared/reveal.directive';
import { Ripple } from '../../../../shared/ripple.directive';

@Component({
  selector: 'fm-cta-band',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, Ripple],
  template: `
    <div class="fm-container inner" fmReveal>
      <span class="fm-eyebrow fm-eyebrow--on-dark">Get started free</span>

      <h2>Ready to move your freight?</h2>
      <p>
        Join thousands of Australian businesses using FreightMove to save time and money on
        transport.
      </p>

      <div class="buttons">
        <a class="fm-btn fm-btn--lg" routerLink="/register" fmRipple>
          Post a load
          <fm-icon name="arrow-right" size="16" [strokeWidth]="2.2" />
        </a>
        <a class="fm-btn fm-btn--lg fm-btn--on-dark" routerLink="/register" fmRipple>
          Find loads
          <fm-icon name="arrow-right" size="16" [strokeWidth]="2.2" />
        </a>
      </div>

      <ul class="assurances">
        @for (point of assurances; track point) {
          <li><fm-icon name="check" size="13" [strokeWidth]="3" />{{ point }}</li>
        }
      </ul>
    </div>
  `,
  styleUrl: './cta-band.scss',
})
export class CtaBand {
  protected readonly assurances = [
    'Free to post',
    'No obligation',
    'Quotes within the hour',
    'Australia wide',
  ];
}
