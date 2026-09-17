import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';

import { GoogleMapsLoader } from '../core/maps/google-maps-loader';

/**
 * The driving route between a load's two ends.
 *
 * Carried over from the previous site, which drew the same thing with
 * `DirectionsService` and a `DirectionsRenderer` on every load page. It answers
 * a question the text cannot: "Townsville to Brisbane" is 1,350 km of Bruce
 * Highway, and a carrier deciding whether the job suits them reads that off a
 * line on a map faster than off two suburb names.
 *
 * **Per load, never per row.** Directions is billed per request, so a board of
 * twenty loads drawing twenty routes would be twenty billed calls every time
 * anyone opened the page. The previous site put this on the load page for the
 * same reason, and that is where it stays.
 *
 * It removes itself when it cannot draw anything — no key, a blocked script, a
 * lane Google cannot route (an island pickup, a typo'd suburb). The route is
 * an enrichment; the addresses above it are the fact, and they are already on
 * the page.
 */
@Component({
  selector: 'fm-route-map',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    // Collapses only once routing has definitively failed. Never before the
    // map is built — see the note on .frame.
    '[hidden]': 'failed()',
  },
  template: `
    <div class="frame" [class.is-drawn]="drawn()">
      <div #canvas class="canvas"></div>
      @if (distance(); as summary) {
        <p class="summary">
          <strong>{{ summary.distance }}</strong>
          <span>by road</span>
          <em>·</em>
          <strong>{{ summary.duration }}</strong>
          <span>driving</span>
        </p>
      }
    </div>
  `,
  styles: `
    :host {
      display: block;
    }

    /*
     * Laid out from the first frame, and revealed with opacity rather than
     * display.
     *
     * Google measures the container when the map is constructed. Building one
     * inside a display-none box gives it a 0x0 viewport, and it stays blank
     * afterwards even once the box is shown — which is precisely what happened
     * when this hid itself until the route arrived.
     *
     * (No backticks in here: these styles live in a template literal.)
     */
    .frame {
      overflow: hidden;
      border-radius: var(--fm-radius);
      box-shadow: 0 0 0 1px var(--fm-line);
      background: var(--fm-paper-alt);
      opacity: 0;
      transition: opacity 0.35s var(--fm-ease-expo, ease);
    }

    .frame.is-drawn {
      opacity: 1;
    }

    @media (prefers-reduced-motion: reduce) {
      .frame {
        transition: none;
      }
    }

    .canvas {
      width: 100%;
      /* Tall enough to show a capital-to-capital lane without dominating the
         page. Shorter on a phone, where vertical space is the scarce thing. */
      height: 20rem;
    }

    @media (max-width: 34rem) {
      .canvas {
        height: 14rem;
      }
    }

    .summary {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 0.35rem;
      margin: 0;
      padding: 0.7rem 0.9rem;
      border-top: 1px solid var(--fm-line);
      background: var(--fm-paper-alt);
      color: var(--fm-ink-soft);
      font-size: var(--fm-text-sm);
    }

    .summary strong {
      color: var(--fm-ink);
      font-weight: 700;
      font-variant-numeric: tabular-nums;
    }

    .summary em {
      color: var(--fm-ink-faint);
      font-style: normal;
    }
  `,
})
export class RouteMap {
  readonly origin = input<string | null>(null);
  readonly destination = input<string | null>(null);

  /** False until a route is actually on screen; drives the fade, not layout. */
  protected readonly drawn = signal(false);

  /**
   * True once we know there will be no map: no key, no Maps, or a lane Google
   * will not route. Only then does the component collapse — doing it earlier
   * would mean building the map in a box with no size.
   */
  protected readonly failed = signal(false);

  /** Road distance and driving time, which the lane text cannot give. */
  protected readonly distance = signal<{ distance: string; duration: string } | null>(null);

  private readonly canvas = viewChild<ElementRef<HTMLElement>>('canvas');
  private readonly maps = inject(GoogleMapsLoader);

  /** Guards against a second map on the same element. */
  private started = false;

  constructor() {
    effect(() => {
      const from = this.origin();
      const to = this.destination();
      const host = this.canvas();

      // Once only. The effect re-runs if any signal it reads changes, and a
      // second Map on the same element would stack another billed Directions
      // call on top of a map nobody asked for twice.
      if (from && to && host && !this.started) {
        this.started = true;
        void this.draw(from, to, host.nativeElement);
      }
    });
  }

  private async draw(origin: string, destination: string, host: HTMLElement): Promise<void> {
    /*
     * Nothing below may leave the frame sitting there blank.
     *
     * The component only collapses once `failed` is set, so any path that
     * neither draws nor fails leaves a tall white gap in the middle of the
     * page — which is what a missing `importLibrary` produced the first time.
     * The watchdog covers the cases that do not throw and never call back,
     * such as a Directions request that is silently dropped.
     */
    const watchdog = setTimeout(() => {
      if (!this.drawn()) {
        this.failed.set(true);
      }
    }, 12000);

    try {
      await this.drawOrThrow(origin, destination, host, watchdog);
    } catch {
      clearTimeout(watchdog);
      this.failed.set(true);
    }
  }

  private async drawOrThrow(
    origin: string,
    destination: string,
    host: HTMLElement,
    watchdog: ReturnType<typeof setTimeout>,
  ): Promise<void> {
    if (!(await this.maps.load())) {
      throw new Error('maps unavailable');
    }

    const maps = (window as unknown as { google?: { maps?: any } }).google?.maps;

    /*
     * `loading=async` is the current bootstrap, and on it `google.maps` starts
     * out holding little more than `importLibrary`. `Map` and
     * `DirectionsService` do not exist until their libraries are pulled in —
     * so reaching straight for them, as the previous site could on the old
     * synchronous loader, finds nothing.
     */
    if (maps?.importLibrary) {
      await Promise.all([maps.importLibrary('maps'), maps.importLibrary('routes')]);
    }

    if (!maps?.Map || !maps?.DirectionsService) {
      throw new Error('maps libraries missing');
    }

    const map = new maps.Map(host, {
      // Centred on the continent until the route arrives and reframes it, so
      // the first paint is never the middle of the Pacific.
      center: { lat: -25.5, lng: 134 },
      zoom: 4,
      disableDefaultUI: true,
      zoomControl: true,
      gestureHandling: 'cooperative',
    });

    const renderer = new maps.DirectionsRenderer({
      map,
      suppressMarkers: false,
      polylineOptions: { strokeColor: '#2f6fd0', strokeWeight: 5, strokeOpacity: 0.9 },
    });

    new maps.DirectionsService().route(
      {
        origin,
        destination,
        travelMode: maps.TravelMode?.DRIVING ?? 'DRIVING',
        region: 'au',
      },
      (result: any, status: string) => {
        clearTimeout(watchdog);

        if (status !== 'OK' || !result) {
          // No route, no map. An empty grey rectangle says less than nothing.
          //
          // REQUEST_DENIED here almost always means the Directions API is not
          // enabled on the Cloud project, or the key is restricted to other
          // APIs. It is a separate product from Maps JavaScript and Places,
          // and enabling those does not enable this.
          if (status === 'REQUEST_DENIED') {
            console.warn(
              '[route-map] Directions refused the request. Enable the Directions API ' +
                'on the Google Cloud project and allow it on the key.',
            );
          }

          this.failed.set(true);
          return;
        }

        renderer.setDirections(result);
        this.drawn.set(true);

        const leg = result.routes?.[0]?.legs?.[0];

        if (leg?.distance?.text && leg?.duration?.text) {
          this.distance.set({ distance: leg.distance.text, duration: leg.duration.text });
        }
      },
    );
  }
}
