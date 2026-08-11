import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * The heading block every marketing section opens with: optional eyebrow pill,
 * display heading, supporting line.
 *
 * Wrap a phrase in asterisks to accent it — `title="How *FreightMove* Works"`
 * renders the middle segment in brand red. Splitting a plain string beats
 * innerHTML here: no sanitiser, and the copy stays readable in the source.
 */
@Component({
  selector: 'fm-section-head',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[class.align-start]': "align() === 'start'",
    '[class.tone-dark]': "tone() === 'dark'",
  },
  template: `
    @if (eyebrow()) {
      <span class="fm-eyebrow" [class.fm-eyebrow--on-dark]="tone() === 'dark'">{{ eyebrow() }}</span>
    }

    <h2>
      @for (segment of segments(); track $index) {
        @if (segment.accent) {
          <em>{{ segment.text }}</em>
        } @else {
          <span>{{ segment.text }}</span>
        }
      }
    </h2>

    @if (subtitle()) {
      <p>{{ subtitle() }}</p>
    }
  `,
  styleUrl: './section-head.scss',
})
export class SectionHead {
  readonly eyebrow = input('');
  readonly title = input.required<string>();
  readonly subtitle = input('');
  readonly align = input<'center' | 'start'>('center');
  readonly tone = input<'light' | 'dark'>('light');

  /**
   * `split` with a capturing group puts the captured (accented) pieces at odd
   * indices, so parity identifies them. Empty pieces are dropped afterwards to
   * keep the parity check honest.
   */
  protected readonly segments = computed(() =>
    this.title()
      .split(/\*([^*]+)\*/g)
      .map((text, index) => ({ text, accent: index % 2 === 1 }))
      .filter((segment) => segment.text !== ''),
  );
}
