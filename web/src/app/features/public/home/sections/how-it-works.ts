import { ChangeDetectionStrategy, Component } from '@angular/core';

import { Icon } from '../../../../shared/icon';
import { IconName } from '../../../../shared/icons';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';

@Component({
  selector: 'fm-how-it-works',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, Reveal, SectionHead],
  template: `
    <div class="fm-container">
      <fm-section-head
        eyebrow="How it works"
        title="Five steps from posted to *delivered*"
        subtitle="No phone tag, no chasing quotes. Post once and let verified carriers come to you."
        fmReveal
      />

      <ol class="steps">
        @for (step of steps; track step.title; let i = $index) {
          <li [fmReveal]="i">
            <span class="disc">
              @if (step.image) {
                <!-- alt is empty: the step number and title beside it already
                     carry the meaning, so describing the badge would repeat it. -->
                <img [src]="step.image" alt="" width="300" height="300" loading="lazy" decoding="async" />
              } @else {
                <fm-icon [name]="step.icon" size="34" [strokeWidth]="1.5" />
              }
              <span class="num">{{ i + 1 }}</span>
            </span>
            <h3>{{ step.title }}</h3>
            <p>{{ step.body }}</p>
          </li>
        }
      </ol>
    </div>
  `,
  styleUrl: './how-it-works.scss',
})
export class HowItWorks {
  /** `icon` is the fallback glyph, used only when a step has no `image` yet. */
  protected readonly steps: { title: string; body: string; icon: IconName; image?: string }[] = [
    {
      title: 'Post your load',
      body: 'Tell us what, where and when.',
      icon: 'clipboard',
      image: '/post-your-load.webp',
    },
    {
      title: 'Receive quotes',
      body: 'Verified carriers send competitive prices.',
      icon: 'inbox',
      image: '/receive-quotes.webp',
    },
    {
      title: 'Compare & choose',
      body: 'Weigh price, rating and equipment side by side.',
      icon: 'scale',
      image: '/compare-choose.webp',
    },
    {
      title: 'Book & confirm',
      body: 'Lock in the job and get ready for pickup.',
      icon: 'handshake',
      image: '/book-confirm.webp',
    },
    {
      title: 'Track & deliver',
      body: 'Follow your freight every step of the way.',
      icon: 'map-pin',
      image: '/track-deliver.webp',
    },
  ];
}
