import { HttpClient, HttpParams } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';

interface PaymentRow {
  id: number;
  gateway: string | null;
  reference: string | null;
  amount: number;
  currency: string;
  status: string;
  paid_at: string | null;
  plan: string | null;
  payer: { name: string | null; email: string | null };
  carrier: { id: number | null; name: string | null; email: string | null };
  is_legacy: boolean;
}

interface Summary {
  collected: number;
  collected_paypal: number;
  collected_manual: number;
  payments: number;
  awaiting: number;
  payers: number;
  last_paid_at: string | null;
}

interface Page {
  items: PaymentRow[];
  summary: Summary;
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/**
 * Every payment taken for a subscription.
 *
 * The previous site's `myadmin/payment_transaction`, which had no equivalent
 * here — so $5,775.23 of imported PayPal history sat in the database with no
 * screen that could show it.
 *
 * Read-only, and no refund button. Refunds belong in PayPal where the money
 * is; a button that marks a row without moving funds is worse than no button,
 * because it reports success. Refunds done there arrive on the webhook.
 */
@Component({
  selector: 'fm-admin-payments',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './admin-payments.html',
  styleUrl: './admin-payments.scss',
})
export class AdminPayments {
  protected readonly gateways = [
    { value: '', label: 'All gateways' },
    { value: 'paypal', label: 'PayPal' },
    { value: 'manual', label: 'Manual' },
  ];

  protected readonly statuses = [
    { value: '', label: 'Every status' },
    { value: 'completed', label: 'Paid' },
    { value: 'pending', label: 'Awaiting payment' },
    { value: 'refunded', label: 'Refunded' },
  ];

  protected readonly page = signal<Page | null>(null);
  protected readonly gateway = signal('');
  protected readonly status = signal('');
  protected readonly search = signal('');
  protected readonly pageNumber = signal(1);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/admin/payments`;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    let params = new HttpParams().set('page', String(this.pageNumber()));

    if (this.gateway()) {
      params = params.set('gateway', this.gateway());
    }

    if (this.status()) {
      params = params.set('status', this.status());
    }

    if (this.search().trim()) {
      params = params.set('q', this.search().trim());
    }

    this.http.get<ApiEnvelope<Page>>(this.base, { params }).subscribe({
      next: (response) => {
        this.page.set(response.data);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load payments.'));
      },
    });
  }

  /** Any filter change resets to page one — page 4 of a new filter is nothing. */
  protected filterBy(field: 'gateway' | 'status', value: string): void {
    field === 'gateway' ? this.gateway.set(value) : this.status.set(value);
    this.pageNumber.set(1);
    this.load();
  }

  protected runSearch(): void {
    this.pageNumber.set(1);
    this.load();
  }

  protected goTo(page: number): void {
    this.pageNumber.set(page);
    this.load();
  }

  protected money(value: number, currency = 'AUD'): string {
    return new Intl.NumberFormat('en-AU', {
      style: 'currency',
      currency,
      currencyDisplay: 'narrowSymbol',
    }).format(value ?? 0);
  }

  protected dateTime(iso: string | null): string {
    if (!iso) {
      return '—';
    }

    return new Date(iso).toLocaleString('en-AU', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  }

  protected date(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-AU') : '—';
  }
}
