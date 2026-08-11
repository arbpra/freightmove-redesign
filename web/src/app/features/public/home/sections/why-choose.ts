import { ChangeDetectionStrategy, Component } from '@angular/core';

import { Icon } from '../../../../shared/icon';
import { IconName } from '../../../../shared/icons';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';
import { Spotlight } from '../../../../shared/spotlight.directive';

@Component({
  selector: 'fm-why-choose',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, Reveal, SectionHead, Spotlight],
  template: `
    <div class="fm-container">
      <fm-section-head
        eyebrow="Why FreightMove"
        title="Built for operators who move *real freight*"
        subtitle="Every carrier is checked, every quote is comparable, and nothing commits you until you say so."
        fmReveal
      />

      <ul class="cards">
        @for (reason of reasons; track reason.title; let i = $index) {
          <li [fmReveal]="i" fmSpotlight>
            <span class="glyph">
              @if (reason.image) {
                <!-- alt is empty: the heading directly below names the benefit,
                     so describing the badge would repeat it to screen readers. -->
                <img
                  [src]="reason.image"
                  alt=""
                  width="200"
                  height="200"
                  loading="lazy"
                  decoding="async"
                />
              } @else {
                <fm-icon [name]="reason.icon" size="20" [strokeWidth]="1.7" />
              }
            </span>
            <h3>{{ reason.title }}</h3>
            <p>{{ reason.body }}</p>
          </li>
        }
      </ul>
    </div>
  `,
  styleUrl: './why-choose.scss',
})
export class WhyChoose {
  /** `icon` is the fallback glyph, used only when a reason has no `image`. */
  protected readonly reasons: { title: string; body: string; icon: IconName; image?: string }[] = [
    {
      title: 'Verified carriers',
      body: 'ABN, insurance and operating credentials are checked before anyone can quote on your freight.',
      icon: 'shield-check',
      image: '/verified-carriers.webp',
    },
    {
      title: 'Competitive quotes',
      body: 'Multiple carriers compete for the same load, so you see the real market price — not one operator’s.',
      icon: 'price-tag',
      image: '/competitive-quotes.webp',
    },
    {
      title: 'Australia wide',
      body: 'A national network covering metro lanes, regional runs and remote sites coast to coast.',
      icon: 'globe',
      image: '/australia-wide.webp',
    },
    {
      title: 'Fast turnaround',
      body: 'Most loads attract their first quote within the hour, so you can plan the same day.',
      icon: 'clock',
      image: '/fast-turnaround.webp',
    },
    {
      title: 'Secure platform',
      body: 'Your data and every transaction are encrypted end to end and never shared with third parties.',
      icon: 'lock',
      image: '/secure-platform.webp',
    },
    {
      title: 'No obligation',
      body: 'Compare freely and book only when the number and the carrier both look right.',
      icon: 'thumbs-up',
      image: '/no-obligation.webp',
    },
  ];
}
