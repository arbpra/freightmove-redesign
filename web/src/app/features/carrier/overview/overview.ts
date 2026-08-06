import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { AuthService } from '../../../core/auth/auth.service';

interface CarrierSummary {
  open_loads: number;
  quotes: { total: number; pending: number; accepted: number };
  fleet_size: number;
  verification_status: string | null;
  rating: string | null;
  unread_notifications: number;
}

@Component({
  selector: 'fm-carrier-overview',
  template: `
    <h1>Carrier dashboard</h1>
    <p class="muted">Signed in as {{ auth.user()?.name }}</p>

    @if (data(); as overview) {
      <div class="tiles">
        <div class="tile">
          <span class="value">{{ overview.open_loads }}</span>
          <span class="label">Loads open for quotes</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.quotes.pending }}</span>
          <span class="label">Quotes awaiting reply</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.quotes.accepted }}</span>
          <span class="label">Jobs won</span>
        </div>
        <div class="tile">
          <span class="value">{{ overview.fleet_size }}</span>
          <span class="label">Active vehicles</span>
        </div>
      </div>

      <p>
        Verification: <strong>{{ overview.verification_status ?? 'unverified' }}</strong>
        @if (overview.rating) {
          · Rating: <strong>{{ overview.rating }}</strong>
        }
      </p>
    } @else {
      <p class="muted">Loading…</p>
    }
  `,
  styleUrl: '../../dashboard.scss',
})
export class CarrierOverview {
  protected readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);

  protected readonly data = signal<CarrierSummary | null>(null);

  constructor() {
    this.http
      .get<ApiEnvelope<CarrierSummary>>(`${environment.apiUrl}/carrier/overview`)
      .subscribe((response) => this.data.set(response.data));
  }
}
