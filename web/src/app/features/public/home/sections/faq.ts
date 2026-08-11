import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';

/**
 * Native <details> gives us keyboard support, find-in-page and no-JS expansion
 * for free — worth more here than a hand-rolled accordion. The open/close
 * height animation rides on `interpolate-size`, with a plain fade fallback in
 * browsers that do not support it yet.
 */
@Component({
  selector: 'fm-faq',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, SectionHead],
  template: `
    <div class="fm-container">
      <fm-section-head
        eyebrow="FAQ"
        title="Questions, answered *straight*"
        fmReveal
      />

      <div class="columns">
        @for (item of faqs; track item.question; let i = $index) {
          <details [fmReveal]="i" [revealStep]="45">
            <summary>
              <span>{{ item.question }}</span>
              <span class="chev"><fm-icon name="plus" size="15" [strokeWidth]="2.2" /></span>
            </summary>
            <div class="answer"><p>{{ item.answer }}</p></div>
          </details>
        }
      </div>

      <p class="more" fmReveal>
        Still deciding?
        <a routerLink="/register">Post a load free</a> and see the numbers before you commit.
      </p>
    </div>
  `,
  styleUrl: './faq.scss',
})
export class Faq {
  protected readonly faqs = [
    {
      question: 'How do I post a load on FreightMove?',
      answer:
        'Create a free account, then tell us the pickup and delivery locations, what you are moving and when it needs to go. Verified carriers on that lane are notified straight away.',
    },
    {
      question: 'Can I track my freight?',
      answer:
        'Yes. Once a quote is accepted the job appears in your dashboard with status updates from pickup through to delivery, and you can message the carrier directly.',
    },
    {
      question: 'Is it free to post a load?',
      answer:
        'Posting a load and receiving quotes is completely free. You only ever pay the carrier you choose to book — there are no listing fees.',
    },
    {
      question: 'What if I need to change my booking?',
      answer:
        'Message the carrier through the job thread as early as you can. Until a job is picked up you can update the details or cancel without penalty.',
    },
    {
      question: 'How do carriers quote on my load?',
      answer:
        'Carriers matching your freight type and route see your load on their board and submit a price with an available pickup window. You compare and choose.',
    },
    {
      question: 'Are carriers verified?',
      answer:
        'Every carrier is checked for ABN, insurance and operating credentials before they can quote. Ratings from completed jobs are shown on each profile.',
    },
    {
      question: 'How do I choose the right carrier?',
      answer:
        'Each quote shows the price, the carrier rating, completed job count and equipment type, so you can weigh cost against track record rather than price alone.',
    },
    {
      question: 'What types of freight can I post?',
      answer:
        'General and palletised freight, containers, machinery, heavy haulage, livestock, grain and hay, bulk tipper, liquid tanker, boats and portable buildings.',
    },
  ];
}
