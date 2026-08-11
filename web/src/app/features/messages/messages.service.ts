import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../environments/environment';
import { ApiEnvelope } from '../../core/auth/auth.models';

export interface ConversationJob {
  id: number | null;
  title: string | null;
  status: string | null;
  lane: string | null;
}

export interface ConversationSummary {
  id: number;
  job: ConversationJob;
  with: { id: number | null; name: string | null };
  unread_count: number;
  last_message: { body: string | null; sent_by_me: boolean; created_at: string | null } | null;
}

export interface ConversationList {
  items: ConversationSummary[];
  unread_total: number;
}

export interface ChatMessage {
  id: number;
  body: string | null;
  sent_by_me: boolean;
  sender_name: string | null;
  read_at: string | null;
  created_at: string | null;
}

export interface Thread {
  id: number;
  job: ConversationJob;
  with: { id: number | null; name: string | null };
  /** False once the load is closed — history stays readable, the box does not. */
  can_send: boolean;
  items: ChatMessage[];
}

@Injectable({ providedIn: 'root' })
export class MessagesService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/conversations`;

  list(): Observable<ConversationList> {
    return this.http
      .get<ApiEnvelope<ConversationList>>(this.base)
      .pipe(map((response) => response.data));
  }

  thread(id: number): Observable<Thread> {
    return this.http
      .get<ApiEnvelope<Thread>>(`${this.base}/${id}`)
      .pipe(map((response) => response.data));
  }

  send(id: number, body: string): Observable<ChatMessage> {
    return this.http
      .post<ApiEnvelope<ChatMessage>>(`${this.base}/${id}/messages`, { body })
      .pipe(map((response) => response.data));
  }

  /**
   * Opens the thread for a load, or returns the one already there.
   *
   * `withUserId` is only needed when the caller posted the load, since it can
   * have many carriers quoting on it.
   */
  open(jobId: number, withUserId?: number): Observable<number> {
    return this.http
      .post<ApiEnvelope<{ id: number }>>(this.base, {
        job_id: jobId,
        with_user_id: withUserId ?? null,
      })
      .pipe(map((response) => response.data.id));
  }
}
