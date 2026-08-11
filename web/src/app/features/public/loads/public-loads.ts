import { HttpClient, HttpParams } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { AuthService } from '../../../core/auth/auth.service';
import { Seo } from '../../../core/seo/seo.service';
import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';

export interface PublicLoad {
  ref: string;
  title: string;
  pickup: string;
  delivery: string;
  category: string | null;
  truck_type: string | null;
  availability: string | null;
  pickup_date: string | null;
  weight_tons: number | null;
  quotes_count: number;
  posted_at: string | null;
}

interface Board {
  items: PublicLoad[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  categories: string[];
  quoting: { requires_subscription: boolean; requires_verification: boolean };
}

/**
 * The public load board — every load currently open for quotes.
 *
 * Open to anyone. A carrier weighing up a subscription should be able to see
 * whether there is freight on their lanes before paying for anything; quoting
 * is what needs an account, not looking.
 *
 * Shipper details are absent from the API response entirely rather than hidden
 * in the template — see PublicLoadResource. Nothing here could leak them even
 * if the markup were wrong.
 */
@Component({
  selector: 'fm-public-loads',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, RouterLink, Icon, Ripple],
  templateUrl: './public-loads.html',
  styleUrl: './public-loads.scss',
})
export class PublicLoads {
  protected readonly auth = inject(AuthService);

  protected readonly board = signal<Board | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly search = signal('');
  protected readonly category = signal('');

  private readonly http = inject(HttpClient);

  /**
   * What the "quote on this" button should say, given who is reading.
   *
   * Derived from the API's own report of the rules rather than hardcoded, so
   * the page cannot promise something the gate will refuse.
   */
  protected readonly quoteCta = computed(() => {
    const quoting = this.board()?.quoting;

    if (!this.auth.isAuthenticated()) {
      return { label: 'Sign in to quote', link: '/login' };
    }

    if (this.auth.role() !== 'carrier') {
      return { label: 'Carriers quote on loads', link: '/register' };
    }

    if (quoting?.requires_subscription) {
      return { label: 'Subscribe to quote', link: '/carrier/subscription' };
    }

    return { label: 'Open the load board', link: '/carrier/board' };
  });

  constructor() {
    inject(Seo).apply({
      title: 'Live Freight Load Board Australia | FreightMove',
      description:
        'Browse freight loads open for quotes across Australia. See lanes, freight types and weights — no account needed to look.',
      path: '/load-board',
    });

    this.load();
  }

  protected load(page = 1): void {
    this.loading.set(true);
    this.error.set(null);

    let params = new HttpParams().set('page', String(page));

    if (this.search()) {
      params = params.set('search', this.search());
    }

    if (this.category()) {
      params = params.set('category', this.category());
    }

    this.http
      .get<ApiEnvelope<Board>>(`${environment.apiUrl}/public/loads`, { params })
      .subscribe({
        next: (response) => {
          this.board.set(response.data);
          this.loading.set(false);
        },
        error: (response) => {
          this.loading.set(false);
          this.error.set(describeError(response, 'Could not load the board just now.'));
        },
      });
  }

  protected filter(): void {
    this.load();
  }

  protected chooseCategory(value: string): void {
    this.category.set(value);
    this.load();
  }

  protected posted(iso: string | null): string {
    if (!iso) {
      return '';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 3600) {
      return `${Math.max(1, Math.floor(seconds / 60))} min ago`;
    }

    const format = new Intl.RelativeTimeFormat('en-AU', { numeric: 'auto' });
    const hours = Math.floor(seconds / 3600);

    if (hours < 24) {
      return format.format(-hours, 'hour');
    }

    const days = Math.floor(seconds / 86400);

    return days > 7 ? new Date(iso).toLocaleDateString('en-AU') : format.format(-days, 'day');
  }
}
