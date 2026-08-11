import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, shareReplay } from 'rxjs';

import { environment } from '../../../environments/environment';
import { ApiEnvelope } from '../../core/auth/auth.models';
import { CheckoutResult, PlanList, SubscriptionState } from './subscription.models';

@Injectable({ providedIn: 'root' })
export class SubscriptionService {
  private readonly http = inject(HttpClient);

  /**
   * The public pricing table.
   *
   * Shared and replayed: the marketing page and the carrier's own subscription
   * page both need it, and the prices do not change between two clicks.
   */
  readonly plans$ = this.http
    .get<ApiEnvelope<PlanList>>(`${environment.apiUrl}/public/subscription-plans`)
    .pipe(
      map((response) => response.data),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

  state(): Observable<SubscriptionState> {
    return this.http
      .get<ApiEnvelope<SubscriptionState>>(`${environment.apiUrl}/carrier/subscription`)
      .pipe(map((response) => response.data));
  }

  startTrial(): Observable<ApiEnvelope<{ ends_on: string | null }>> {
    return this.http.post<ApiEnvelope<{ ends_on: string | null }>>(
      `${environment.apiUrl}/carrier/subscription/trial`,
      {},
    );
  }

  /**
   * Reserves a plan and starts payment.
   *
   * A redirect gateway answers with `approval_url`; an offline one answers with
   * instructions instead. The caller decides what to do with each.
   */
  checkout(plan: string): Observable<ApiEnvelope<CheckoutResult>> {
    return this.http.post<ApiEnvelope<CheckoutResult>>(
      `${environment.apiUrl}/carrier/subscription/checkout`,
      { plan },
    );
  }

  /**
   * Completes payment after the gateway sends the carrier back.
   *
   * Only the reference travels: whether it was paid, and for how much, is
   * settled server-side with the gateway.
   */
  capture(reference: string): Observable<ApiEnvelope<{ status: string; ends_on: string | null }>> {
    return this.http.post<ApiEnvelope<{ status: string; ends_on: string | null }>>(
      `${environment.apiUrl}/carrier/subscription/capture`,
      { reference },
    );
  }

  cancel(id: number): Observable<ApiEnvelope<null>> {
    return this.http.post<ApiEnvelope<null>>(
      `${environment.apiUrl}/carrier/subscription/${id}/cancel`,
      {},
    );
  }
}
