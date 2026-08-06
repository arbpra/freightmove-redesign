import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { AuthService } from '../../../core/auth/auth.service';

interface AdminSummary {
  users: { shippers: number; carriers: number };
  jobs: { total: number; open: number; completed: number; disputed: number };
  quotes: number;
  awaiting_approval: number;
  open_tickets: number;
}

@Component({
  selector: 'fm-admin-overview',
  template: `
    <h1>Admin console</h1>
    <p class="muted">Signed in as {{ auth.user()?.name }}</p>

    @if (data(); as overview) {
      <div class="tiles">
        <div class="tile">
          <span class="value">{{ overview.users.shippers }}</span>
          <span class="label">Shippers</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.users.carriers }}</span>
          <span class="label">Carriers</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.jobs.total }}</span>
          <span class="label">Jobs</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.quotes }}</span>
          <span class="label">Quotes</span>
        </div>
        <div class="tile" [class.attention]="overview.awaiting_approval > 0">
          <span class="value">{{ overview.awaiting_approval }}</span>
          <span class="label">Documents to approve</span>
        </div>
        <div class="tile" [class.attention]="overview.open_tickets > 0">
          <span class="value">{{ overview.open_tickets }}</span>
          <span class="label">Open tickets</span>
        </div>
        <div class="tile" [class.attention]="overview.jobs.disputed > 0">
          <span class="value">{{ overview.jobs.disputed }}</span>
          <span class="label">Disputed jobs</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.jobs.completed }}</span>
          <span class="label">Completed jobs</span>
        </div>
      </div>
    } @else {
      <p class="muted">Loading…</p>
    }
  `,
  styleUrl: '../../dashboard.scss',
})
export class AdminOverview {
  protected readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);

  protected readonly data = signal<AdminSummary | null>(null);

  constructor() {
    this.http
      .get<ApiEnvelope<AdminSummary>>(`${environment.apiUrl}/admin/overview`)
      .subscribe((response) => this.data.set(response.data));
  }
}
