import { HttpClient, HttpParams } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';

interface QueuedSubscription {
  id: number;
  status: string;
  plan: string | null;
  amount: number;
  starts_on: string | null;
  ends_on: string | null;
  carrier: { id: number | null; name: string | null; email: string | null };
  requested_at: string | null;
}

interface Page {
  items: QueuedSubscription[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/**
 * Subscriptions waiting on payment.
 *
 * Under the manual gateway this is how money gets recognised: a carrier
 * reserves a plan, pays by whatever was agreed, and it is confirmed here. With
 * a real gateway wired in, its webhook does this and the queue becomes the
 * exception path.
 */
@Component({
  selector: 'fm-admin-subscriptions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, Ripple],
  templateUrl: './admin-subscriptions.html',
  styleUrl: './admin-subscriptions.scss',
})
export class AdminSubscriptions {
  protected readonly filters = [
    { value: 'pending', label: 'Awaiting payment' },
    { value: 'active', label: 'Active' },
    { value: 'expired', label: 'Expired' },
    { value: 'cancelled', label: 'Cancelled' },
  ];

  protected readonly page = signal<Page | null>(null);
  protected readonly status = signal('pending');
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly busyId = signal<number | null>(null);

  protected readonly confirming = signal<QueuedSubscription | null>(null);
  protected readonly reference = signal('');

  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/admin/subscriptions`;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    const params = new HttpParams().set('status', this.status());

    this.http.get<ApiEnvelope<Page>>(this.base, { params }).subscribe({
      next: (response) => {
        this.page.set(response.data);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load subscriptions.'));
      },
    });
  }

  protected filterBy(status: string): void {
    this.status.set(status);
    this.load();
  }

  protected startConfirm(subscription: QueuedSubscription): void {
    this.confirming.set(subscription);
    this.reference.set('');
  }

  protected confirm(): void {
    const subscription = this.confirming();

    if (!subscription) {
      return;
    }

    this.busyId.set(subscription.id);
    this.confirming.set(null);

    this.http
      .post<ApiEnvelope<unknown>>(`${this.base}/${subscription.id}/confirm`, {
        reference: this.reference().trim() || null,
      })
      .subscribe({
        next: (response) => {
          this.busyId.set(null);
          this.notice.set(response.message);
          this.load();
        },
        error: (response) => {
          this.busyId.set(null);
          this.error.set(describeError(response, 'Could not confirm that payment.'));
        },
      });
  }

  protected waitedDays(iso: string | null): number {
    if (!iso) {
      return 0;
    }

    return Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  }

  protected date(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-AU') : '—';
  }
}
