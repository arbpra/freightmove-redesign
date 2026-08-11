import { ChangeDetectionStrategy, Component } from '@angular/core';

import { Icon } from '../../../../shared/icon';
import { IconName } from '../../../../shared/icons';
import { Reveal } from '../../../../shared/reveal.directive';

@Component({
  selector: 'fm-industries',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, Reveal],
  template: `
    <div class="fm-container">
      <div class="head" fmReveal>
        <p class="kicker">Trusted across</p>
        <h2>Every sector that moves freight</h2>
      </div>

      <ul class="row">
        @for (industry of industries; track industry.label; let i = $index) {
          <li [fmReveal]="i" [revealStep]="50">
            <span class="plate">
              @if (industry.image) {
                <!-- alt is empty: the label directly below names the industry,
                     so describing the badge would repeat it. -->
                <img
                  [src]="industry.image"
                  alt=""
                  width="200"
                  height="200"
                  loading="lazy"
                  decoding="async"
                />
              } @else {
                <fm-icon [name]="industry.icon" size="28" [strokeWidth]="1.4" />
              }
            </span>
            <span class="label">{{ industry.label }}</span>
          </li>
        }
      </ul>
    </div>
  `,
  styleUrl: './industries.scss',
})
export class Industries {
  /** `icon` is the fallback glyph, used only when an entry has no `image`. */
  protected readonly industries: { label: string; icon: IconName; image?: string }[] = [
    { label: 'Construction', icon: 'crane', image: '/construction.webp' },
    { label: 'Mining', icon: 'pickaxe', image: '/mining.webp' },
    { label: 'Agriculture', icon: 'sprout', image: '/agriculture.webp' },
    { label: 'Manufacturing', icon: 'factory', image: '/manufacturing.webp' },
    { label: 'Retail', icon: 'cart', image: '/retail.webp' },
    { label: 'Energy', icon: 'zap', image: '/energy.webp' },
    { label: 'Government', icon: 'landmark', image: '/government.webp' },
    { label: 'Infrastructure', icon: 'network', image: '/infrastructure.webp' },
  ];
}
