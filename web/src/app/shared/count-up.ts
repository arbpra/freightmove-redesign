import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';

/**
 * Counts a number up to its target the first time it scrolls into view.
 *
 * Eased with the same expo curve as the reveals so the two read as one motion.
 * Under reduced motion — or without IntersectionObserver — it renders the final
 * value immediately.
 */
@Component({
  selector: 'fm-count-up',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `{{ prefix() }}{{ display() }}{{ suffix() }}`,
  styles: `
    :host {
      /* Tabular figures stop the number jittering as digits change. */
      font-variant-numeric: tabular-nums;
      font-feature-settings: 'tnum' 1;
    }
  `,
})
export class CountUp implements OnInit {
  readonly value = input.required<number>();
  readonly decimals = input(0);
  readonly prefix = input('');
  readonly suffix = input('');
  readonly duration = input(1400);

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly current = signal(0);
  private frame = 0;
  private observer?: IntersectionObserver;

  protected readonly display = computed(() =>
    this.current().toLocaleString('en-AU', {
      minimumFractionDigits: this.decimals(),
      maximumFractionDigits: this.decimals(),
    }),
  );

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      cancelAnimationFrame(this.frame);
      this.observer?.disconnect();
    });
  }

  ngOnInit(): void {
    const skip =
      typeof IntersectionObserver === 'undefined' ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (skip) {
      this.current.set(this.value());
      return;
    }

    this.observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          this.observer?.disconnect();
          this.run();
        }
      },
      { threshold: 0.4 },
    );

    this.observer.observe(this.host.nativeElement);
  }

  private run(): void {
    const target = this.value();
    const total = this.duration();
    const start = performance.now();

    const tick = (now: number) => {
      const progress = Math.min((now - start) / total, 1);
      // Expo-out: matches --fm-ease-expo closely enough to feel like one system.
      const eased = 1 - Math.pow(2, -10 * progress);
      this.current.set(target * (progress === 1 ? 1 : eased));

      if (progress < 1) {
        this.frame = requestAnimationFrame(tick);
      }
    };

    this.frame = requestAnimationFrame(tick);
  }
}
