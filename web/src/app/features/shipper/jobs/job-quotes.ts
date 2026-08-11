import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';

import { describeError } from '../../../core/http/describe-error';
import { MessagesService } from '../../messages/messages.service';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { JobQuote, QuotesForJob } from './job.models';
import { JobService } from './job.service';

/**
 * Quote comparison for one load.
 *
 * Deciding is deliberately two-step: accepting books the load, declines every
 * other quote and cannot be undone, so it asks first.
 */
@Component({
  selector: 'fm-job-quotes',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Ripple],
  templateUrl: './job-quotes.html',
  styleUrl: './job-quotes.scss',
})
export class JobQuotes {
  /** Bound from the :id route segment. */
  readonly id = input.required<string>();

  protected readonly data = signal<QuotesForJob | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly busyId = signal<number | null>(null);
  /** The quote awaiting confirmation, if any. */
  protected readonly confirming = signal<JobQuote | null>(null);

  private readonly jobs = inject(JobService);
  private readonly messages = inject(MessagesService);
  private readonly router = inject(Router);

  constructor() {
    queueMicrotask(() => this.load());
  }

  /**
   * Opens (or reuses) the thread with this carrier and goes to it.
   *
   * The API decides whether a thread may exist — the carrier has quoted, which
   * by definition they have if they are on this page.
   */
  protected message(quote: JobQuote): void {
    const carrierId = quote.carrier?.id;
    const jobId = this.data()?.job.id;

    if (!carrierId || !jobId) {
      return;
    }

    this.busyId.set(quote.id);

    this.messages.open(jobId, carrierId).subscribe({
      next: (conversationId) => {
        this.busyId.set(null);
        void this.router.navigate(['/messages', conversationId]);
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not open that conversation.'));
      },
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.jobs.quotes(Number(this.id())).subscribe({
      next: (result) => {
        this.data.set(result);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load the quotes for this load.'));
      },
    });
  }

  protected confirm(quote: JobQuote): void {
    this.confirming.set(quote);
  }

  protected cancelConfirm(): void {
    this.confirming.set(null);
  }

  protected accept(quote: JobQuote): void {
    this.busyId.set(quote.id);
    this.error.set(null);

    this.jobs.acceptQuote(quote.id).subscribe({
      next: () => {
        this.busyId.set(null);
        this.confirming.set(null);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.confirming.set(null);
        this.error.set(describeError(response, 'Could not accept that quote.'));
      },
    });
  }

  protected decline(quote: JobQuote): void {
    this.busyId.set(quote.id);
    this.error.set(null);

    this.jobs.declineQuote(quote.id).subscribe({
      next: () => {
        this.busyId.set(null);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not decline that quote.'));
      },
    });
  }

  /** Cheapest quote, used to badge the best price. */
  protected isCheapest(quote: JobQuote): boolean {
    const items = this.data()?.items ?? [];
    return items.length > 1 && quote.amount === Math.min(...items.map((q) => q.amount));
  }

  protected carrierLabel(quote: JobQuote): string {
    return quote.carrier?.company_name || quote.carrier?.name || 'Carrier';
  }
}
