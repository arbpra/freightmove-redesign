import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { DocumentStatus } from '../../carrier/profile/profile.models';

export interface QueuedDocument {
  id: number;
  document_type: string;
  original_name: string | null;
  mime_type: string | null;
  size_bytes: number | null;
  status: DocumentStatus;
  expires_at: string | null;
  uploaded_at: string | null;
  carrier: {
    id: number;
    name: string;
    email: string;
    company_name: string | null;
    abn_acn: string | null;
    verification_status: string | null;
  };
}

export interface QueuePage {
  items: QueuedDocument[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface DecisionResult {
  document_status: DocumentStatus;
  carrier_verification_status: string;
  still_missing: string[];
}

@Injectable({ providedIn: 'root' })
export class VerificationQueueService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/admin`;

  list(status: DocumentStatus = 'pending', page = 1): Observable<QueuePage> {
    const params = new HttpParams().set('status', status).set('page', String(page));

    return this.http
      .get<ApiEnvelope<QueuePage>>(`${this.base}/verifications`, { params })
      .pipe(map((response) => response.data));
  }

  approve(id: number, note?: string, expiresAt?: string): Observable<ApiEnvelope<DecisionResult>> {
    return this.http.post<ApiEnvelope<DecisionResult>>(`${this.base}/documents/${id}/approve`, {
      note: note || null,
      expires_at: expiresAt || null,
    });
  }

  reject(id: number, note: string): Observable<ApiEnvelope<DecisionResult>> {
    return this.http.post<ApiEnvelope<DecisionResult>>(`${this.base}/documents/${id}/reject`, {
      note,
    });
  }

  /**
   * The document itself. Private disk behind a policy, so this has to travel as
   * an authorised request rather than a plain link.
   */
  download(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/documents/${id}/download`, { responseType: 'blob' });
  }
}
