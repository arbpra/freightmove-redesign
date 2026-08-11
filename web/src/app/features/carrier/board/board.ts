import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { toSignal } from '@angular/core/rxjs-interop';

import { describeError, fieldErrors } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { FreightJob, LoadAvailability } from '../../shipper/jobs/job.models';
import { JobService } from '../../shipper/jobs/job.service';
import { BoardPage, BoardQuery, BoardService } from './board.service';

@Component({
  selector: 'fm-load-board',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, Ripple],
  templateUrl: './board.html',
  styleUrl: './board.scss',
})
export class LoadBoard {
  /** Shared with the shipper form so both use one vocabulary. */
  protected readonly taxonomy = toSignal(inject(JobService).taxonomy$, { initialValue: null });

  protected readonly states = ['NSW', 'QLD', 'VIC', 'WA', 'SA', 'TAS', 'NT', 'ACT'];

  protected readonly page = signal<BoardPage | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  /** False when a subscription is required and this carrier has none. */
  protected readonly canQuote = signal(true);

  // Filters
  protected readonly pickupState = signal<string>('');
  protected readonly deliveryState = signal<string>('');
  protected readonly availability = signal<LoadAvailability | ''>('');
  protected readonly truckTypeId = signal<number | ''>('');
  protected readonly search = signal('');
  protected readonly unquoted = signal(false);

  // Quote form, opened inline against one load at a time.
  protected readonly quoting = signal<FreightJob | null>(null);
  protected readonly amount = signal<number | null>(null);
  protected readonly notes = signal('');
  protected readonly sending = signal(false);
  protected readonly quoteError = signal<string | null>(null);

  private readonly board = inject(BoardService);

  constructor() {
    this.load();
  }

  protected load(page = 1): void {
    this.loading.set(true);
    this.error.set(null);

    const query: BoardQuery = {
      pickup_state: this.pickupState(),
      delivery_state: this.deliveryState(),
      availability: this.availability(),
      truck_type_id: this.truckTypeId(),
      search: this.search(),
      unquoted: this.unquoted(),
      page,
    };

    this.board.board(query).subscribe({
      next: (result) => {
        this.page.set(result.page);
        this.canQuote.set(result.canQuote);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load the board.'));
      },
    });
  }

  protected clearFilters(): void {
    this.pickupState.set('');
    this.deliveryState.set('');
    this.availability.set('');
    this.truckTypeId.set('');
    this.search.set('');
    this.unquoted.set(false);
    this.load();
  }

  protected openQuote(job: FreightJob): void {
    this.quoting.set(job);
    this.amount.set(null);
    this.notes.set('');
    this.quoteError.set(null);
  }

  protected cancelQuote(): void {
    this.quoting.set(null);
  }

  protected submitQuote(): void {
    const job = this.quoting();
    const amount = this.amount();

    if (!job) {
      return;
    }

    if (!amount || amount <= 0) {
      this.quoteError.set('Enter the price you would charge for this load.');
      return;
    }

    this.sending.set(true);
    this.quoteError.set(null);

    this.board.quote(job.id, { amount, notes: this.notes() || null }).subscribe({
      next: () => {
        this.sending.set(false);
        this.quoting.set(null);
        // Refetch so the row flips to "quoted" from the server's own answer
        // rather than being patched optimistically here.
        this.load(this.page()?.meta.current_page ?? 1);
      },
      error: (response: HttpErrorResponse) => {
        this.sending.set(false);
        const detail = fieldErrors(response)[0];
        this.quoteError.set(detail ?? describeError(response, 'Could not send that quote.'));
      },
    });
  }

  protected lane(job: FreightJob): string {
    return `${job.pickup_location} → ${job.delivery_location}`;
  }
}
