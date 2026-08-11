import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../../environments/environment';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { CarrierProfileDraft, CarrierProfilePayload, VerificationDocument } from './profile.models';

/**
 * The carrier's own profile and verification documents.
 *
 * No id is ever passed for the profile itself — the API scopes everything to
 * the signed-in carrier, so there is nothing here that could address someone
 * else's record.
 */
@Injectable({ providedIn: 'root' })
export class CarrierProfileService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/carrier`;

  get(): Observable<CarrierProfilePayload> {
    return this.http
      .get<ApiEnvelope<CarrierProfilePayload>>(`${this.base}/profile`)
      .pipe(map((response) => response.data));
  }

  update(changes: CarrierProfileDraft): Observable<ApiEnvelope<CarrierProfilePayload>> {
    return this.http.patch<ApiEnvelope<CarrierProfilePayload>>(`${this.base}/profile`, changes);
  }

  /**
   * Uploads a document.
   *
   * Sent as multipart, so the Content-Type header is left for the browser to
   * set — it has to include the multipart boundary, and any value we set by
   * hand would be missing it.
   */
  upload(
    documentType: string,
    file: File,
    expiresAt?: string | null,
  ): Observable<ApiEnvelope<VerificationDocument>> {
    const body = new FormData();
    body.append('document_type', documentType);
    body.append('file', file);

    if (expiresAt) {
      body.append('expires_at', expiresAt);
    }

    return this.http.post<ApiEnvelope<VerificationDocument>>(`${this.base}/documents`, body);
  }

  removeDocument(id: number): Observable<ApiEnvelope<null>> {
    return this.http.delete<ApiEnvelope<null>>(`${this.base}/documents/${id}`);
  }

  /**
   * These files sit on a private disk behind an authorising policy, so there is
   * no URL that works on its own — the browser cannot simply follow a link. The
   * blob is fetched with the auth interceptor attached and handed to the user
   * from memory.
   */
  download(document: VerificationDocument): Observable<Blob> {
    return this.http.get(`${this.base}/documents/${document.id}/download`, {
      responseType: 'blob',
    });
  }
}
