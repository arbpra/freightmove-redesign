import { HttpClient, HttpParams } from '@angular/common/http';
import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { Observable, map, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import { ApiEnvelope } from '../auth/auth.models';
import { AuthService } from '../auth/auth.service';

export interface AppNotification {
  id: number;
  type: string;
  title: string;
  body: string | null;
  is_read: boolean;
  related_type: string | null;
  related_id: number | null;
  created_at: string | null;
}

export interface NotificationFeed {
  items: AppNotification[];
  unread_count: number;
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/** How often the badge refreshes while the tab is in front. */
const POLL_MS = 60_000;

/**
 * The notification feed and its unread badge.
 *
 * There is no websocket, so the badge polls. Two things keep that honest: it
 * asks a count-only endpoint rather than pulling twenty rows to render one
 * number, and it stops entirely while the tab is hidden — a backgrounded tab
 * left open overnight should not spend the night talking to the API.
 */
@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);
  private readonly document = inject(DOCUMENT);

  private readonly base = `${environment.apiUrl}/notifications`;

  readonly unreadCount = signal(0);

  private timer: ReturnType<typeof setInterval> | null = null;

  constructor() {
    const onVisibility = () => {
      if (this.document.hidden) {
        this.stopPolling();
        return;
      }

      // Catch up immediately on return, then resume the timer.
      this.refreshCount();
      this.startPolling();
    };

    this.document.addEventListener('visibilitychange', onVisibility);

    inject(DestroyRef).onDestroy(() => {
      this.document.removeEventListener('visibilitychange', onVisibility);
      this.stopPolling();
    });
  }

  /** Called by the dashboard shell once a session is established. */
  start(): void {
    this.refreshCount();
    this.startPolling();
  }

  list(unreadOnly = false): Observable<NotificationFeed> {
    let params = new HttpParams();

    if (unreadOnly) {
      params = params.set('unread', '1');
    }

    return this.http.get<ApiEnvelope<NotificationFeed>>(this.base, { params }).pipe(
      map((response) => response.data),
      tap((feed) => this.unreadCount.set(feed.unread_count)),
    );
  }

  markRead(id: number): Observable<number> {
    return this.http
      .patch<ApiEnvelope<{ unread_count: number }>>(`${this.base}/${id}/read`, {})
      .pipe(
        map((response) => response.data.unread_count),
        tap((count) => this.unreadCount.set(count)),
      );
  }

  markAllRead(): Observable<number> {
    return this.http.post<ApiEnvelope<{ unread_count: number }>>(`${this.base}/read-all`, {}).pipe(
      map((response) => response.data.unread_count),
      tap((count) => this.unreadCount.set(count)),
    );
  }

  refreshCount(): void {
    if (!this.auth.isAuthenticated()) {
      return;
    }

    this.http
      .get<ApiEnvelope<{ unread_count: number }>>(`${this.base}/unread-count`)
      .subscribe({
        next: (response) => this.unreadCount.set(response.data.unread_count),
        // A failed poll is not worth telling anyone about; the next one will
        // either work or the interceptor will have signed them out.
        error: () => undefined,
      });
  }

  private startPolling(): void {
    this.stopPolling();
    this.timer = setInterval(() => this.refreshCount(), POLL_MS);
  }

  private stopPolling(): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }
}
