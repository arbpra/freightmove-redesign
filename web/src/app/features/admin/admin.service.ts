import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../environments/environment';
import { ApiEnvelope } from '../../core/auth/auth.models';

export type AccountStatus = 'pending' | 'active' | 'suspended' | 'blocked';

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: string;
  status: AccountStatus;
  company_name: string | null;
  verification_status: string | null;
  /** Came across from the old platform — the question that matters at cut-over. */
  is_legacy: boolean;
  jobs_count: number;
  quotes_count: number;
  created_at: string | null;
}

/** One account in full, as the detail endpoint returns it. */
export interface AdminUserDetail extends AdminUser {
  first_name: string | null;
  last_name: string | null;
  wants_load_alerts: boolean;
  email_verified: boolean;
  password_changed_at: string | null;
  profile: {
    company_name: string | null;
    abn_acn: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    bio: string | null;
  };
}

/** What an admin may change. Role and status are deliberately absent. */
export interface AdminUserEdit {
  name?: string;
  first_name?: string | null;
  last_name?: string | null;
  email?: string;
  phone?: string | null;
  wants_load_alerts?: boolean;
  profile?: Partial<AdminUserDetail['profile']>;
}

export interface AdminJob {
  id: number;
  title: string;
  status: string;
  lane: string;
  quotes_count: number;
  shipper: { id: number | null; name: string | null; email: string | null };
  is_legacy: boolean;
  created_at: string | null;
}

export interface Paged<T> {
  items: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface AdminOverview {
  users: { shippers: number; carriers: number; suspended: number; new_this_month: number };
  jobs: {
    total: number;
    open: number;
    completed: number;
    disputed: number;
    new_this_month: number;
  };
  quotes: { total: number; pending: number; accepted: number; new_this_month: number };
  marketplace: {
    window_days: number;
    loads_posted: number;
    loads_with_a_quote: number;
    loads_booked: number;
    quote_rate: number;
    booking_rate: number;
    average_quotes_per_load: number;
    open_without_quotes: number;
  };
  queues: { documents_awaiting_review: number; open_tickets: number };
  migration: {
    legacy_users: number;
    legacy_users_signed_in: number;
    legacy_carriers: number;
    carriers_verified: number;
    carriers_with_active_subscription: number;
    gates: {
      verification_required_to_quote: boolean;
      subscription_required_to_quote: boolean;
    };
  };
}

export interface UserQuery {
  role?: string;
  status?: string;
  search?: string;
  legacy?: string;
  page?: number;
}

@Injectable({ providedIn: 'root' })
export class AdminService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/admin`;

  overview(): Observable<AdminOverview> {
    return this.http
      .get<ApiEnvelope<AdminOverview>>(`${this.base}/overview`)
      .pipe(map((response) => response.data));
  }

  users(query: UserQuery = {}): Observable<Paged<AdminUser>> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http
      .get<ApiEnvelope<Paged<AdminUser>>>(`${this.base}/users`, { params })
      .pipe(map((response) => response.data));
  }

  setStatus(id: number, status: AccountStatus): Observable<ApiEnvelope<AdminUser>> {
    return this.http.post<ApiEnvelope<AdminUser>>(`${this.base}/users/${id}/status`, { status });
  }

  user(id: number): Observable<ApiEnvelope<AdminUserDetail>> {
    return this.http.get<ApiEnvelope<AdminUserDetail>>(`${this.base}/users/${id}`);
  }

  updateUser(id: number, changes: AdminUserEdit): Observable<ApiEnvelope<AdminUserDetail>> {
    return this.http.patch<ApiEnvelope<AdminUserDetail>>(`${this.base}/users/${id}`, changes);
  }

  /**
   * Set a new password on someone else's account.
   *
   * `revoke_sessions` defaults to true on the server and is sent explicitly
   * here, because leaving it to a default is how it ends up false one day: a
   * reset that keeps live tokens does not take the account back from whoever
   * had it, which is usually the reason for the reset.
   */
  setUserPassword(
    id: number,
    password: string,
    reason?: string,
  ): Observable<ApiEnvelope<AdminUserDetail>> {
    return this.http.post<ApiEnvelope<AdminUserDetail>>(`${this.base}/users/${id}/password`, {
      password,
      revoke_sessions: true,
      reason,
    });
  }

  jobs(query: { status?: string; search?: string; page?: number } = {}): Observable<Paged<AdminJob>> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http
      .get<ApiEnvelope<Paged<AdminJob>>>(`${this.base}/jobs`, { params })
      .pipe(map((response) => response.data));
  }
}
