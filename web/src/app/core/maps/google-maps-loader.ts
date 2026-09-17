import { Injectable, inject, signal } from '@angular/core';

import { ClientConfigService } from '../config/client-config.service';

/** Why the Maps library is or is not available. Diagnosis, not user copy. */
export type MapsStatus = 'idle' | 'no-key' | 'loading' | 'ready' | 'blocked';

/**
 * The Google Maps bootstrap, loaded once for the whole app.
 *
 * This exists as its own service because **two script tags is a bug**. Google's
 * loader warns and misbehaves when the API is included twice, so the moment a
 * second feature wanted Maps — the route map on a load — the injection could no
 * longer live privately inside the autocomplete service that happened to need
 * it first.
 *
 * Loaded on demand: the bootstrap is a few hundred kilobytes and most visits
 * never open a page that needs it.
 *
 * The key comes from `GET /api/v1/public/config`, not the bundle, because
 * `deploy/web` is committed — anything compiled into the build lands in git.
 * See ClientConfigService.
 *
 * Every failure resolves false rather than throwing. Callers fall back to
 * something that still works without a map, and none of them may break a page
 * over it.
 */
@Injectable({ providedIn: 'root' })
export class GoogleMapsLoader {
  readonly status = signal<MapsStatus>('idle');

  private attempt: Promise<boolean> | null = null;

  private readonly config = inject(ClientConfigService);

  /** Resolves true once `window.google.maps` is usable. Safe to call often. */
  load(): Promise<boolean> {
    this.attempt ??= this.begin();

    return this.attempt;
  }

  private async begin(): Promise<boolean> {
    this.status.set('loading');

    const { google_maps_key: key } = await this.config.load();

    if (!key) {
      this.status.set('no-key');
      return false;
    }

    try {
      await this.inject(key);
      this.status.set('ready');
      return true;
    } catch {
      this.status.set('blocked');
      return false;
    }
  }

  /**
   * Adds the bootstrap script, once.
   *
   * Resolution comes from Google's `callback` parameter rather than the
   * script's `load` event: with an async bootstrap the two are not the same
   * moment, and `google.maps` can still be unpopulated when `load` fires.
   *
   * A timeout backs it up, because the callback simply never runs when the key
   * is rejected — there is no error event either — and a promise that never
   * settles leaves every caller waiting forever instead of falling back.
   */
  private inject(key: string): Promise<void> {
    const READY = '__fmMapsReady';

    return new Promise((resolve, reject) => {
      const w = window as unknown as Record<string, unknown> & {
        google?: { maps?: unknown };
      };

      if (w.google?.maps) {
        resolve();
        return;
      }

      if (document.querySelector('script[data-fm-maps]')) {
        // Another caller is already loading it; wait on the same callback.
        const existing = w[READY] as (() => void) | undefined;
        w[READY] = () => {
          existing?.();
          resolve();
        };
        return;
      }

      const timer = setTimeout(() => reject(new Error('maps callback never fired')), 10000);

      w[READY] = () => {
        clearTimeout(timer);
        resolve();
      };

      const script = document.createElement('script');

      // `places` is for the address fields; directions are part of core and
      // need no library of their own.
      script.src =
        `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}` +
        `&libraries=places&v=weekly&loading=async&callback=${READY}`;
      script.async = true;
      script.dataset['fmMaps'] = '';
      script.addEventListener('error', () => {
        clearTimeout(timer);
        reject(new Error('maps script failed to load'));
      });

      document.head.appendChild(script);
    });
  }
}
