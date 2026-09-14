import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { environment } from '../../../environments/environment';
import { ApiEnvelope } from '../auth/auth.models';

/** What `GET /public/config` hands the browser. */
export interface ClientConfig {
  google_maps_key: string | null;
  paypal_client_id: string | null;
  paypal_mode: string;
  /** The largest photo this server will really take, in kilobytes. */
  load_max_image_kb: number;
  load_max_images: number;
}

/**
 * Settings the server decides and the browser needs.
 *
 * Fetched once and shared, because several unrelated parts of the app want
 * pieces of it and none of them should each cost a request.
 *
 * The upload limit is the reason this exists as a service rather than a
 * constant. It cannot be hardcoded in the bundle: the real ceiling is PHP's
 * `upload_max_filesize`, which differs per host and is 2M on a stock install
 * against a 6MB product limit. Baking a number in would mean the form promises
 * whatever was true on the developer's machine.
 */
@Injectable({ providedIn: 'root' })
export class ClientConfigService {
  /**
   * Conservative defaults, used until the real answer arrives and if it never
   * does. 2MB is PHP's own default, so it is the safest thing to assume.
   */
  private static readonly FALLBACK: ClientConfig = {
    google_maps_key: null,
    paypal_client_id: null,
    paypal_mode: 'sandbox',
    load_max_image_kb: 2048,
    load_max_images: 6,
  };

  /** Readable synchronously; starts at the fallback and updates in place. */
  readonly config = signal<ClientConfig>(ClientConfigService.FALLBACK);

  private fetch: Promise<ClientConfig> | null = null;

  private readonly http = inject(HttpClient);

  /**
   * Loads the config, once. Concurrent callers share the request.
   *
   * A failure resolves to the fallback rather than rejecting: nothing here is
   * important enough to break a page over, and every consumer already has to
   * cope with the values being conservative.
   */
  load(): Promise<ClientConfig> {
    this.fetch ??= firstValueFrom(
      this.http.get<ApiEnvelope<ClientConfig>>(`${environment.apiUrl}/public/config`),
    )
      .then((response) => {
        const config = { ...ClientConfigService.FALLBACK, ...(response.data ?? {}) };
        this.config.set(config);
        return config;
      })
      .catch(() => ClientConfigService.FALLBACK);

    return this.fetch;
  }
}
