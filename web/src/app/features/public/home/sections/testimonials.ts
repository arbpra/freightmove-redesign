import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  signal,
  viewChild,
  viewChildren,
} from '@angular/core';

import { Icon } from '../../../../shared/icon';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';

interface Testimonial {
  quote: string;
  name: string;
  role: string;
  rating: number;
}

@Component({
  selector: 'fm-testimonials',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, Reveal, SectionHead],
  templateUrl: './testimonials.html',
  styleUrl: './testimonials.scss',
})
export class Testimonials {
  protected readonly stars = [1, 2, 3, 4, 5];

  protected readonly testimonials: Testimonial[] = [
    {
      quote:
        'We had four quotes within the hour and moved an excavator Brisbane to Perth for less than our usual carrier charges on half that distance.',
      name: 'James R.',
      role: 'Civil Contractor',
      rating: 4,
    },
    {
      quote:
        'Harvest season used to mean a week of phone calls. Now I post the load on Sunday night and the truck is booked before Monday lunch.',
      name: 'Sarah T.',
      role: 'Agricultural Business',
      rating: 4,
    },
    {
      quote:
        'Clear communication, fair prices, on time every run. It is the only platform our logistics team uses for heavy freight now.',
      name: 'Mark D.',
      role: 'Mining Company',
      rating: 5,
    },
  ];

  /** Index of the card currently snapped into view on narrow screens. */
  protected readonly active = signal(0);

  private readonly track = viewChild.required<ElementRef<HTMLElement>>('track');
  private readonly cards = viewChildren<ElementRef<HTMLElement>>('card');

  protected goTo(index: number): void {
    this.cards()[index]?.nativeElement.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'start',
    });
    this.active.set(index);
  }

  protected onScroll(): void {
    const element = this.track().nativeElement;
    const width = element.clientWidth || 1;
    this.active.set(Math.round(element.scrollLeft / width));
  }

  protected initials(name: string): string {
    return name
      .split(' ')
      .map((part) => part.charAt(0))
      .join('')
      .slice(0, 2)
      .toUpperCase();
  }
}
