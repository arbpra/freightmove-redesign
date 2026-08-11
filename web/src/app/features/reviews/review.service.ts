import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../environments/environment';
import { ApiEnvelope } from '../../core/auth/auth.models';

export interface JobReview {
  id: number;
  rating: number;
  comment: string | null;
  by: string | null;
  by_me: boolean;
  created_at: string | null;
}

export interface ReviewsForJob {
  items: JobReview[];
  /** Whether the caller may still write one. */
  can_review: boolean;
  already_reviewed: boolean;
}

@Injectable({ providedIn: 'root' })
export class ReviewService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/jobs`;

  forJob(jobId: number): Observable<ReviewsForJob> {
    return this.http
      .get<ApiEnvelope<ReviewsForJob>>(`${this.base}/${jobId}/reviews`)
      .pipe(map((response) => response.data));
  }

  submit(jobId: number, rating: number, comment: string): Observable<ApiEnvelope<unknown>> {
    return this.http.post<ApiEnvelope<unknown>>(`${this.base}/${jobId}/reviews`, {
      rating,
      comment: comment.trim() || null,
    });
  }
}
