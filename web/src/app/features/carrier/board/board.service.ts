import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { FreightJob, LoadAvailability, Paginated } from '../../shipper/jobs/job.models';

export interface BoardQuery {
  pickup_state?: string;
  delivery_state?: string;
  availability?: LoadAvailability | '';
  truck_type_id?: number | '';
  category_id?: number | '';
  search?: string;
  unquoted?: boolean;
  page?: number;
}

export interface BoardPage extends Paginated<FreightJob> {
  meta: Paginated<FreightJob>['meta'] & { recency_days: number };
}

export interface QuoteDraft {
  amount: number;
  estimated_delivery_date?: string | null;
  notes?: string | null;
}

/** Carrier-side load board and quoting. */
@Injectable({ providedIn: 'root' })
export class BoardService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/carrier`;

  /**
   * The board response also reports whether this carrier may quote, so the UI
   * can explain a locked state rather than letting them fill in a form that
   * will be refused.
   */
  board(query: BoardQuery = {}): Observable<{ page: BoardPage; canQuote: boolean }> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '' && value !== false) {
        params = params.set(key, String(value));
      }
    }

    return this.http
      .get<ApiEnvelope<BoardPage & { can_quote: boolean }>>(`${this.base}/board`, { params })
      .pipe(
        map((response) => ({
          page: { items: response.data.items, meta: response.data.meta },
          canQuote: response.data.can_quote,
        })),
      );
  }

  quote(jobId: number, draft: QuoteDraft): Observable<ApiEnvelope<unknown>> {
    return this.http.post<ApiEnvelope<unknown>>(`${this.base}/board/${jobId}/quotes`, draft);
  }
}
