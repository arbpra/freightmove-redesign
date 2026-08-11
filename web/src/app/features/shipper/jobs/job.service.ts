import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, shareReplay } from 'rxjs';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import {
  FreightJob,
  FreightTaxonomy,
  JobDraft,
  JobListQuery,
  Paginated,
  QuotesForJob,
} from './job.models';

/**
 * Shipper-side freight job API.
 *
 * Every call is scoped server-side to the signed-in shipper, so nothing here
 * passes an owner id — see FreightJobController.
 */
@Injectable({ providedIn: 'root' })
export class JobService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/shipper/jobs`;

  /**
   * The freight vocabulary, served by the API so the client never carries its
   * own copy — the hardcoded lists it used to hold had already drifted from
   * what customers actually select.
   *
   * Shared and replayed, so navigating between the form and the board does not
   * refetch it.
   */
  readonly taxonomy$ = this.http
    .get<ApiEnvelope<FreightTaxonomy>>(`${environment.apiUrl}/public/taxonomy`)
    .pipe(
      map((response) => response.data),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

  list(query: JobListQuery = {}): Observable<Paginated<FreightJob>> {
    let params = new HttpParams();

    // Blank values are dropped so the API never sees `?status=`.
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http
      .get<ApiEnvelope<Paginated<FreightJob>>>(this.base, { params })
      .pipe(map((response) => response.data));
  }

  get(id: number): Observable<FreightJob> {
    return this.http
      .get<ApiEnvelope<FreightJob>>(`${this.base}/${id}`)
      .pipe(map((response) => response.data));
  }

  /** Quotes received on one of my loads, cheapest first. */
  quotes(jobId: number): Observable<QuotesForJob> {
    return this.http
      .get<ApiEnvelope<QuotesForJob>>(`${this.base}/${jobId}/quotes`)
      .pipe(map((response) => response.data));
  }

  acceptQuote(quoteId: number): Observable<ApiEnvelope<unknown>> {
    return this.http.post<ApiEnvelope<unknown>>(
      `${environment.apiUrl}/shipper/quotes/${quoteId}/accept`,
      {},
    );
  }

  declineQuote(quoteId: number): Observable<ApiEnvelope<unknown>> {
    return this.http.post<ApiEnvelope<unknown>>(
      `${environment.apiUrl}/shipper/quotes/${quoteId}/decline`,
      {},
    );
  }

  create(draft: JobDraft): Observable<ApiEnvelope<FreightJob>> {
    return this.http.post<ApiEnvelope<FreightJob>>(this.base, draft);
  }

  update(id: number, changes: Partial<JobDraft>): Observable<ApiEnvelope<FreightJob>> {
    return this.http.patch<ApiEnvelope<FreightJob>>(`${this.base}/${id}`, changes);
  }

  publish(id: number): Observable<ApiEnvelope<FreightJob>> {
    return this.http.post<ApiEnvelope<FreightJob>>(`${this.base}/${id}/publish`, {});
  }

  /**
   * Bumps an open load back to the top of the carrier board.
   *
   * The API refuses with 429 inside the cooldown window and says when the next
   * bump is due, so callers should surface the message rather than retrying.
   */
  relist(id: number): Observable<ApiEnvelope<FreightJob>> {
    return this.http.post<ApiEnvelope<FreightJob>>(`${this.base}/${id}/relist`, {});
  }

  /** The shipper signs a booked load off as delivered. */
  complete(id: number): Observable<ApiEnvelope<FreightJob>> {
    return this.http.post<ApiEnvelope<FreightJob>>(`${this.base}/${id}/complete`, {});
  }

  cancel(id: number): Observable<ApiEnvelope<FreightJob>> {
    return this.http.post<ApiEnvelope<FreightJob>>(`${this.base}/${id}/cancel`, {});
  }

  remove(id: number): Observable<ApiEnvelope<null>> {
    return this.http.delete<ApiEnvelope<null>>(`${this.base}/${id}`);
  }
}
