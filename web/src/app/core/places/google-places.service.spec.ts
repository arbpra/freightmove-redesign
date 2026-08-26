import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { GooglePlacesService } from './google-places.service';

/**
 * Prediction parsing.
 *
 * The two Google autocomplete APIs return different shapes, and the newer one
 * returns `FormattableText` objects rather than strings — an object carrying
 * the text plus the ranges that matched the query. Passing one to `String()`
 * yields "[object Object]", which is how an integration looks broken while
 * every request is succeeding. That is the bug these tests exist to catch.
 *
 * The library is stubbed rather than loaded: these cover our handling of the
 * response, which is the part we own.
 */
describe('GooglePlacesService', () => {
  let service: GooglePlacesService;

  /**
   * Installs a fake Places namespace and skips the script load.
   *
   * A key is planted as well: `load()` fetches one from the API, and without
   * it the service short-circuits before the stub is ever consulted.
   */
  function stubLibrary(places: Record<string, unknown>): void {
    const internals = service as unknown as {
      places: unknown;
      loading: Promise<boolean>;
      failed: boolean;
      warned: boolean;
      key: string | null;
      keyFetch: Promise<string | null>;
    };

    internals.key = 'test-key';
    internals.keyFetch = Promise.resolve('test-key');

    internals.places = places;
    internals.failed = false;
    internals.warned = true; // keep the diagnostic out of the test log
    internals.loading = Promise.resolve(true);
  }

  beforeEach(() => {
    // The service fetches its key from the API now, so it needs an HttpClient
    // even in the tests that never let the request happen.
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(GooglePlacesService);
  });

  it('flattens FormattableText from the new API', async () => {
    stubLibrary({
      AutocompleteSessionToken: class {},
      AutocompleteSuggestion: {
        fetchAutocompleteSuggestions: () =>
          Promise.resolve({
            suggestions: [
              {
                placePrediction: {
                  // Objects, not strings — the shape that broke it.
                  text: { text: '42 Wickham St, Fortitude Valley QLD, Australia' },
                  mainText: { text: '42 Wickham St' },
                  secondaryText: { text: 'Fortitude Valley QLD, Australia' },
                },
              },
            ],
          }),
      },
    });

    const [suggestion] = await service.suggest('42 wickham');

    expect(suggestion.text).toBe('42 Wickham St, Fortitude Valley QLD, Australia');
    expect(suggestion.main).toBe('42 Wickham St');
    expect(suggestion.secondary).toBe('Fortitude Valley QLD, Australia');
    expect(suggestion.text).not.toContain('[object');
  });

  it('reads the legacy API when the new one is not on the project', async () => {
    stubLibrary({
      AutocompleteSessionToken: class {},
      AutocompleteService: class {
        getPlacePredictions(
          _request: unknown,
          done: (predictions: unknown[], status: string) => void,
        ) {
          done(
            [
              {
                description: 'Dubbo NSW, Australia',
                structured_formatting: {
                  main_text: 'Dubbo',
                  secondary_text: 'NSW, Australia',
                },
              },
            ],
            'OK',
          );
        }
      },
    });

    const [suggestion] = await service.suggest('dubbo');

    expect(suggestion.text).toBe('Dubbo NSW, Australia');
    expect(suggestion.main).toBe('Dubbo');
  });

  /** No autocomplete API at all — a project without Places enabled. */
  it('reports an unusable project rather than throwing', async () => {
    stubLibrary({});

    await expectAsync(service.suggest('brisbane')).toBeResolvedTo([]);
    expect(service.status()).toBe('api-not-enabled');
  });

  it('treats no results as a normal answer, not a failure', async () => {
    stubLibrary({
      AutocompleteSessionToken: class {},
      AutocompleteService: class {
        getPlacePredictions(
          _request: unknown,
          done: (predictions: unknown[] | null, status: string) => void,
        ) {
          done(null, 'ZERO_RESULTS');
        }
      },
    });

    await expectAsync(service.suggest('zzzzzz')).toBeResolvedTo([]);
    expect(service.status()).not.toBe('request-failed');
  });

  it('does not call out for one character', async () => {
    let calls = 0;

    stubLibrary({
      AutocompleteSessionToken: class {},
      AutocompleteSuggestion: {
        fetchAutocompleteSuggestions: () => {
          calls += 1;
          return Promise.resolve({ suggestions: [] });
        },
      },
    });

    await service.suggest('b');

    expect(calls).toBe(0);
  });

  /**
   * The billing unit is a session, not a keystroke. The token must survive
   * across the requests that make up one address, and only reset on selection.
   */
  it('keeps one session token until an address is chosen', async () => {
    const issued: object[] = [];

    stubLibrary({
      AutocompleteSessionToken: class {
        constructor() {
          issued.push(this);
        }
      },
      AutocompleteSuggestion: {
        fetchAutocompleteSuggestions: () => Promise.resolve({ suggestions: [] }),
      },
    });

    await service.suggest('bri');
    await service.suggest('brisb');
    expect(issued.length).toBe(1);

    service.endSession();
    await service.suggest('dub');
    expect(issued.length).toBe(2);
  });
});
