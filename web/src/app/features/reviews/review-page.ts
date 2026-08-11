import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Location } from '@angular/common';

import { describeError } from '../../core/http/describe-error';
import { Icon } from '../../shared/icon';
import { Ripple } from '../../shared/ripple.directive';
import { ReviewService, ReviewsForJob } from './review.service';

/**
 * Leaving and reading the reviews on a completed load.
 *
 * Deliberately role-agnostic and on its own route: both the shipper and the
 * carrier review each other, and the carrier has no "my loads" page to hang it
 * off. The API decides who may write one, so this page just asks.
 */
@Component({
  selector: 'fm-review-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, Ripple],
  templateUrl: './review-page.html',
  styleUrl: './review-page.scss',
})
export class ReviewPage {
  /** Bound from the :id route segment. */
  readonly id = input.required<string>();

  protected readonly stars = [1, 2, 3, 4, 5];

  protected readonly data = signal<ReviewsForJob | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly done = signal(false);

  protected readonly rating = signal(0);
  protected readonly hovered = signal(0);
  protected readonly comment = signal('');

  private readonly reviews = inject(ReviewService);
  private readonly location = inject(Location);

  constructor() {
    queueMicrotask(() => this.load());
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.reviews.forJob(Number(this.id())).subscribe({
      next: (data) => {
        this.data.set(data);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load the reviews for this load.'));
      },
    });
  }

  protected back(): void {
    this.location.back();
  }

  protected setRating(value: number): void {
    this.rating.set(value);
  }

  /** Filled to whichever is larger, so hovering previews the choice. */
  protected filled(star: number): boolean {
    return star <= Math.max(this.rating(), this.hovered());
  }

  protected submit(): void {
    if (this.rating() === 0) {
      this.error.set('Choose a rating from one to five stars.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    this.reviews.submit(Number(this.id()), this.rating(), this.comment()).subscribe({
      next: () => {
        this.saving.set(false);
        this.done.set(true);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(describeError(response, 'Could not publish that review.'));
      },
    });
  }
}
