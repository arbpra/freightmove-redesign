import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { AuthService } from '../../../core/auth/auth.service';

interface ShipperSummary {
  jobs: { total: number; by_status: Record<string, number> };
  quotes: { received: number; pending: number };
  unread_notifications: number;
}

@Component({
  selector: 'fm-shipper-overview',
  imports: [RouterLink],
  template: `
    <h1>Shipper dashboard</h1>
    <p class="muted">Signed in as {{ auth.user()?.name }}</p>

    <p class="quick-links">
      <a class="fm-btn fm-btn--sm" routerLink="/shipper/jobs/new">Post a load</a>
      <a class="fm-btn fm-btn--sm fm-btn--ghost" routerLink="/shipper/jobs">My loads</a>
    </p>

    @if (data(); as overview) {
      <div class="tiles">
        <div class="tile">
          <span class="value">{{ overview.jobs.total }}</span>
          <span class="label">Jobs posted</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.quotes.received }}</span>
          <span class="label">Quotes received</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.quotes.pending }}</span>
          <span class="label">Awaiting your decision</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.unread_notifications }}</span>
          <span class="label">Unread notifications</span>
        </div>
      </div>

      <h2>Jobs by status</h2>
      <ul class="statuses">
        @for (entry of statuses(overview); track entry[0]) {
          <li><span>{{ entry[0] }}</span><strong>{{ entry[1] }}</strong></li>
        }
      </ul>
    } @else {
      <p class="muted">Loading…</p>
    }
  `,
  styleUrl: '../../dashboard.scss',
})
export class ShipperOverview {
  protected readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);

  protected readonly data = signal<ShipperSummary | null>(null);

  constructor() {
    this.http
      .get<ApiEnvelope<ShipperSummary>>(`${environment.apiUrl}/shipper/overview`)
      .subscribe((response) => this.data.set(response.data));
  }

  protected statuses(overview: ShipperSummary): [string, number][] {
    return Object.entries(overview.jobs.by_status);
  }
}
