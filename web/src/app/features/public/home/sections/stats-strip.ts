import { ChangeDetectionStrategy, Component } from '@angular/core';

import { CountUp } from '../../../../shared/count-up';
import { Icon } from '../../../../shared/icon';
import { IconName } from '../../../../shared/icons';
import { Reveal } from '../../../../shared/reveal.directive';

interface Stat {
  value: number;
  decimals?: number;
  suffix: string;
  label: string;
  note: string;
  icon: IconName;
}

@Component({
  selector: 'fm-stats-strip',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, CountUp, Reveal],
  template: `
    <div class="fm-container">
      <ul class="strip">
        @for (stat of stats; track stat.label; let i = $index) {
          <li [fmReveal]="i">
            <span class="value">
              <fm-count-up
                [value]="stat.value"
                [decimals]="stat.decimals ?? 0"
                [suffix]="stat.suffix"
              />
            </span>
            <span class="label">
              <fm-icon [name]="stat.icon" size="13" />
              {{ stat.label }}
            </span>
            <span class="note">{{ stat.note }}</span>
          </li>
        }
      </ul>
    </div>
  `,
  styleUrl: './stats-strip.scss',
})
export class StatsStrip {
  protected readonly stats: Stat[] = [
    {
      value: 8000,
      suffix: '+',
      label: 'Loads posted',
      note: 'Every month',
      icon: 'truck-fast',
    },
    {
      value: 2500,
      suffix: '+',
      label: 'Active carriers',
      note: 'Australia wide',
      icon: 'users',
    },
    { value: 15, suffix: '+', label: 'Years of experience', note: 'You can trust', icon: 'shield-check' },
    { value: 4.9, decimals: 1, suffix: '/5', label: 'Customer rating', note: '7,900+ reviews', icon: 'star' },
    {
      value: 100,
      suffix: '%',
      label: 'Nationwide coverage',
      note: 'Coast to coast',
      icon: 'map-pin',
    },
  ];
}
