import { HttpClient, HttpParams } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';

interface ReminderRow {
  id: number;
  kind: 'expiring' | 'expired';
  milestone: string;
  label: string;
  sent_at: string | null;
  skip_reason: string | null;
  recorded_at: string | null;
  carrier: { id: number | null; name: string | null; email: string | null };
  subscription: {
    id: number | null;
    plan: string | null;
    ends_on: string | null;
    status: string | null;
  };
}

interface Summary {
  sent_total: number;
  sent_expiring: number;
  sent_expired: number;
  suppressed: number;
  carriers_reached: number;
  last_sent_at: string | null;
}

interface Page {
  items: ReminderRow[];
  summary: Summary;
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/**
 * Who was sent which subscription reminder, and when.
 *
 * Read-only by design. This is the answer to "did we contact them" — a
 * question that stops meaning anything the moment the record can be edited
 * after the fact. There is no resend button for the same reason: the nightly
 * sweep decides what goes out, and a button here would create a delivery with
 * no milestone behind it.
 *
 * Suppressed rows are shown alongside sent ones. When a long-lapsed carrier is
 * first swept, every past monthly milestone is due at once; only the newest is
 * emailed and the rest are recorded as suppressed. Hiding those would make the
 * ledger look like reminders had gone missing.
 */
@Component({
  selector: 'fm-admin-reminders',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './admin-reminders.html',
  styleUrl: './admin-reminders.scss',
})
export class AdminReminders {
  protected readonly kinds = [
    { value: '', label: 'All reminders' },
    { value: 'expiring', label: 'Before expiry' },
    { value: 'expired', label: 'After expiry' },
  ];

  protected readonly statuses = [
    { value: '', label: 'Sent and suppressed' },
    { value: 'sent', label: 'Sent only' },
    { value: 'suppressed', label: 'Suppressed only' },
  ];

  protected readonly page = signal<Page | null>(null);
  protected readonly kind = signal('');
  protected readonly status = signal('');
  protected readonly search = signal('');
  protected readonly pageNumber = signal(1);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/admin/reminders`;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    let params = new HttpParams().set('page', String(this.pageNumber()));

    if (this.kind()) {
      params = params.set('kind', this.kind());
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
        this.error.set(describeError(response, 'Could not load the reminder record.'));
      },
    });
  }

  /** Any filter change resets to page one — page 4 of a new filter is nothing. */
  protected filterBy(field: 'kind' | 'status', value: string): void {
    field === 'kind' ? this.kind.set(value) : this.status.set(value);
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
