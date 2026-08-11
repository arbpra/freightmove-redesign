import {
  DestroyRef,
  Directive,
  ElementRef,
  OnInit,
  inject,
  input,
  numberAttribute,
} from '@angular/core';

/**
 * Reveals an element as it scrolls into view.
 *
 * One shared IntersectionObserver serves every instance on the page, so a
 * hundred revealed nodes still cost a single observer. Apply a stagger with
 * `[fmReveal]="index"` — each step adds 70ms of delay, which is what gives a
 * grid its cascade instead of a single pop.
 *
 * Elements are only hidden once we know the observer exists and motion is
 * allowed, so content can never end up permanently invisible.
 */
@Directive({
  selector: '[fmReveal]',
})
export class Reveal implements OnInit {
  /** Stagger position within its group. */
  readonly fmReveal = input(0, { transform: numberAttribute });
  /** Milliseconds added per stagger step. */
  readonly revealStep = input(70, { transform: numberAttribute });

  private readonly host = inject(ElementRef<HTMLElement>);

  constructor() {
    inject(DestroyRef).onDestroy(() => Reveal.unobserve(this.host.nativeElement));
  }

  ngOnInit(): void {
    const element = this.host.nativeElement;
    const observer = Reveal.observer();

    if (!observer) {
      return;
    }

    element.style.setProperty('--fm-reveal-delay', `${this.fmReveal() * this.revealStep()}ms`);
    element.classList.add('fm-reveal');
    observer.observe(element);
  }

  // --- Shared observer ------------------------------------------------------

  private static instance: IntersectionObserver | null | undefined;

  private static observer(): IntersectionObserver | null {
    if (Reveal.instance !== undefined) {
      return Reveal.instance;
    }

    const unsupported =
      typeof IntersectionObserver === 'undefined' ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    Reveal.instance = unsupported
      ? null
      : new IntersectionObserver(
          (entries, self) => {
            for (const entry of entries) {
              if (entry.isIntersecting) {
                entry.target.classList.add('is-in');
                // Reveals are one-shot: nothing re-hides on scroll back up.
                self.unobserve(entry.target);
              }
            }
          },
          { rootMargin: '0px 0px -8% 0px', threshold: 0.08 },
        );

    return Reveal.instance;
  }

  private static unobserve(element: Element): void {
    Reveal.instance?.unobserve(element);
  }
}
