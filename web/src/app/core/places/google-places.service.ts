import { Injectable, signal } from '@angular/core';

import { environment } from '../../../environments/environment';

/** One suggestion, flattened to what the UI needs. */
export interface PlaceSuggestion {
  /** The whole thing, as it will be stored: "42 Wickham St, Fortitude Valley QLD". */
  text: string;
  /** The distinctive part, emphasised in the list. */
  main: string;
  /** The rest — suburb, state, country. */
  secondary: string;
}

/** Why autocomplete is or is not running. Surfaced for diagnosis, not for users. */
export type PlacesStatus =
  | 'no-key'
  | 'loading'
  | 'ready'
  | 'script-blocked'
  | 'api-not-enabled'
  | 'request-failed';

/**
 * Google Places autocomplete.
 *
 * Loaded **on demand**. The Maps bootstrap is a few hundred kilobytes and only
 * two fields on one screen need it, so nothing is fetched until someone starts
 * typing an address — a visitor reading the blog never pays for it.
 *
 * Every failure path ends the same way: suggestions stop and the caller falls
 * back to an ordinary text input. No key, a blocked script, a project without
 * the API enabled, an exhausted quota — none of them may stop a shipper posting
 * a load, because the field's real job is to capture a string and it can still
 * do that by hand.
 *
 * `status` exists because that silence is right for a shipper and useless for
 * whoever is setting the key up. It is logged once, in development only.
 *
 * Two prediction APIs are supported. Google's newer `AutocompleteSuggestion`
 * needs **Places API (New)** enabled on the Cloud project; a project with only
 * the original Places API enabled has `AutocompleteService` instead. Rather
 * than make the choice a deployment problem, whichever one the loaded library
 * offers is used.
 *
 * Billing note: predictions are grouped into a **session**. Google bills a
 * session rather than a keystroke, so the token is held for the whole time
 * someone is typing one address and thrown away once they pick something.
 */
@Injectable({ providedIn: 'root' })
export class GooglePlacesService {
  /** False until the library has loaded successfully. */
  readonly available = signal(false);

  readonly status = signal<PlacesStatus>(environment.googleMapsApiKey ? 'loading' : 'no-key');

  /** True once we have given up, so callers stop asking. */
  private failed = false;

  private loading: Promise<boolean> | null = null;

  /** The Places library namespace, once loaded. */
  private places: any = null;

  /** The current billing session, replaced after each completed lookup. */
  private token: any = null;

  /** Legacy predictor, built lazily when the new API is absent. */
  private legacyService: any = null;

  private warned = false;

  /** Whether a key is configured at all. */
  get configured(): boolean {
    return !!environment.googleMapsApiKey;
  }

  /**
   * Loads the library if it is not already loading. Safe to call repeatedly —
   * concurrent callers share one promise and one script tag.
   */
  load(): Promise<boolean> {
    if (this.failed) {
      return Promise.resolve(false);
    }

    if (!this.configured) {
      this.report('no-key');
      return Promise.resolve(false);
    }

    this.loading ??= this.inject()
      .then(async () => {
        const maps = (window as any).google?.maps;

        // `importLibrary` is the current entry point; older bootstraps expose
        // the namespace directly. Either is fine.
        this.places = maps?.importLibrary
          ? await maps.importLibrary('places')
          : maps?.places;

        if (!this.places) {
          throw new Error('places namespace missing');
        }

        this.available.set(true);
        this.status.set('ready');
        return true;
      })
      .catch(() => {
        this.report('script-blocked');
        this.failed = true;
        this.available.set(false);
        return false;
      });

    return this.loading;
  }

  /**
   * Address suggestions for what has been typed so far.
   *
   * Returns an empty list rather than throwing: a failed lookup should leave
   * the field behaving like a text box, not raise an error over a form.
   */
  async suggest(input: string): Promise<PlaceSuggestion[]> {
    const query = input.trim();

    // Two characters is where predictions start being worth a request.
    if (query.length < 2 || !(await this.load())) {
      return [];
    }

    try {
      if (this.places.AutocompleteSuggestion?.fetchAutocompleteSuggestions) {
        return await this.viaNewApi(query);
      }

      if (this.places.AutocompleteService) {
        return await this.viaLegacyApi(query);
      }

      this.report('api-not-enabled');
      return [];
    } catch {
      this.report('request-failed');
      return [];
    }
  }

  /**
   * Call once the shipper has chosen an address. Ends the billing session, so
   * the next field starts a new one.
   */
  endSession(): void {
    this.token = null;
  }

  // -- The two prediction APIs ----------------------------------------------

  /** Places API (New). */
  private async viaNewApi(query: string): Promise<PlaceSuggestion[]> {
    this.token ??= new this.places.AutocompleteSessionToken();

    const { suggestions } = await this.places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
      input: query,
      sessionToken: this.token,
      // Australian freight. Without this the list fills with same-named
      // streets on the other side of the world.
      includedRegionCodes: ['au'],
      language: 'en-AU',
      region: 'au',
    });

    return (suggestions ?? [])
      .map((item: any) => item?.placePrediction)
      .filter(Boolean)
      .map((prediction: any) => {
        const whole = this.plain(prediction.text);
        const main = this.plain(prediction.mainText) || whole;

        return {
          text: whole,
          main,
          secondary: this.plain(prediction.secondaryText),
        };
      })
      .filter((suggestion: PlaceSuggestion) => suggestion.text !== '');
  }

  /** The original Places library, for projects without the new API enabled. */
  private viaLegacyApi(query: string): Promise<PlaceSuggestion[]> {
    this.legacyService ??= new this.places.AutocompleteService();
    this.token ??= new this.places.AutocompleteSessionToken();

    return new Promise((resolve) => {
      this.legacyService.getPlacePredictions(
        {
          input: query,
          sessionToken: this.token,
          componentRestrictions: { country: 'au' },
        },
        (predictions: any[] | null, requestStatus: string) => {
          if (requestStatus !== 'OK' || !predictions) {
            // ZERO_RESULTS is a normal answer, not a fault.
            if (requestStatus !== 'ZERO_RESULTS') {
              this.report('request-failed');
            }
            resolve([]);
            return;
          }

          resolve(
            predictions.map((prediction) => ({
              text: String(prediction.description ?? ''),
              main: String(prediction.structured_formatting?.main_text ?? prediction.description ?? ''),
              secondary: String(prediction.structured_formatting?.secondary_text ?? ''),
            })),
          );
        },
      );
    });
  }

  /**
   * Flattens Google's `FormattableText` to a plain string.
   *
   * `placePrediction.text` is an object carrying the string plus the ranges
   * that matched the query — not a string. Passing it straight to `String()`
   * yields "[object Object]", which is how an integration looks broken while
   * every request is succeeding.
   */
  private plain(value: any): string {
    if (value == null) {
      return '';
    }

    if (typeof value === 'string') {
      return value;
    }

    return String(value.text ?? value.toString?.() ?? '');
  }

  /** Records why autocomplete is off, and says so once during development. */
  private report(status: PlacesStatus): void {
    this.status.set(status);

    if (environment.production || this.warned) {
      return;
    }

    this.warned = true;

    const advice: Record<PlacesStatus, string> = {
      'no-key': 'No googleMapsApiKey in src/environments — the address fields stay plain text.',
      loading: '',
      ready: '',
      'script-blocked':
        'The Maps script did not load. Check the key, its HTTP-referrer restriction, and any ad blocker.',
      'api-not-enabled':
        'Maps loaded but no autocomplete API is present. Enable "Places API (New)" (or the classic Places API) on the Cloud project.',
      'request-failed':
        'A prediction request was rejected. Usual causes: billing not enabled, a referrer restriction that excludes this origin, or quota.',
    };

    console.warn(`[places] ${advice[status]}`);
  }

  /** Adds the Maps bootstrap script, once. */
  private inject(): Promise<void> {
    return new Promise((resolve, reject) => {
      const existing = document.querySelector<HTMLScriptElement>('script[data-fm-maps]');

      if (existing) {
        existing.addEventListener('load', () => resolve());
        existing.addEventListener('error', () => reject());
        return;
      }

      const script = document.createElement('script');
      const key = encodeURIComponent(environment.googleMapsApiKey);

      script.src = `https://maps.googleapis.com/maps/api/js?key=${key}&libraries=places&v=weekly&loading=async`;
      script.async = true;
      script.defer = true;
      script.dataset['fmMaps'] = '';
      script.addEventListener('load', () => resolve());
      script.addEventListener('error', () => reject());

      document.head.appendChild(script);
    });
  }
}
