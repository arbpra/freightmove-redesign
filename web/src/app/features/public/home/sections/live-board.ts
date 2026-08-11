import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import { ApiEnvelope } from '../../../../core/auth/auth.models';
import { Icon } from '../../../../shared/icon';
import { Reveal } from '../../../../shared/reveal.directive';

interface PublicLoad {
  ref: string;
  title: string;
  pickup: string;
  delivery: string;
  category: string | null;
  availability: string | null;
  weight_tons: number | null;
  quotes_count: number;
  posted_at: string | null;
}

interface PublicBoard {
  items: PublicLoad[];
  open_total: number;
}

/**
 * The live board on the home page.
 *
 * This replaced a "Get quotes in minutes" form. The form could not do what it
 * said: a guest cannot post a load, so submitting it sent them to registration
 * — and the origin, destination and weight they had typed were dropped on the
 * way, because registration only reads the role. Asking for details and then
 * discarding them is worse than not asking.
 *
 * Real freight is a better argument anyway. A carrier deciding whether to sign
 * up can see there are loads on their lane; a shipper can see the marketplace
 * is busy. Both are true statements rather than a promise.
 */
@Component({
  selector: 'fm-live-board',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal],
  templateUrl: './live-board.html',
  styleUrl: './live-board.scss',
})
export class LiveBoard {
  protected readonly board = signal<PublicBoard | null>(null);
  protected readonly loading = signal(true);
  protected readonly failed = signal(false);

  private readonly http = inject(HttpClient);

  constructor() {
    this.http
      .get<ApiEnvelope<PublicBoard>>(`${environment.apiUrl}/public/loads/recent`)
      .subscribe({
        next: (response) => {
          this.board.set(response.data);
          this.loading.set(false);
        },
        // A marketing section is not worth an error message on the home page.
        // If the API is unreachable the whole band simply does not render.
        error: () => {
          this.loading.set(false);
          this.failed.set(true);
        },
      });
  }

  /** "2 hours ago" — freshness is the whole point of showing these. */
  protected posted(iso: string | null): string {
    if (!iso) {
      return '';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 3600) {
      const minutes = Math.max(1, Math.floor(seconds / 60));
      return `${minutes} min ago`;
    }

    const format = new Intl.RelativeTimeFormat('en-AU', { numeric: 'auto' });

    for (const [unit, divisor] of [
      ['day', 86400],
      ['hour', 3600],
    ] as [Intl.RelativeTimeFormatUnit, number][]) {
      const value = Math.floor(seconds / divisor);

      if (value >= 1) {
        return unit === 'day' && value > 7
          ? new Date(iso).toLocaleDateString('en-AU')
          : format.format(-value, unit);
      }
    }

    return 'just now';
  }
}
