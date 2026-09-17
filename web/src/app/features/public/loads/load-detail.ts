import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { AuthService } from '../../../core/auth/auth.service';
import { ApiEnvelope } from '../../../core/auth/auth.models';
import { describeError } from '../../../core/http/describe-error';
import { Seo } from '../../../core/seo/seo.service';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { RouteMap } from '../../../shared/route-map';

export interface LoadDetail {
  ref: string;
  title: string;
  pickup: string;
  delivery: string;
  pickup_date: string | null;
  delivery_date: string | null;
  availability: string | null;
  category: string | null;
  categories: { name: string; slug: string }[] | null;
  truck_type: string | null;
  truck_types: { name: string; slug: string }[] | null;
  vehicle_type: string | null;
  quantity: string | null;
  length_mm: number | null;
  width_mm: number | null;
  height_mm: number | null;
  dimensions_label: string | null;
  weight_kg: number | null;
  weight_tons: number | null;
  images: string[];
  quotes_count: number;
  posted_at: string | null;
  description: string | null;
  budget_min: number | null;
  budget_max: number | null;
  /** True for a signed-out visitor: description and budget are withheld. */
  is_restricted: boolean;
  /** Populated only for a carrier holding a current subscription. */
  shipper: {
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    location: string | null;
    member_since: string | null;
  } | null;
  /** Why `shipper` is null: 'guest', 'subscribe', or null once released. */
  shipper_locked: 'guest' | 'subscribe' | null;
  /**
   * Whether a subscription, rather than merely an account, is what stands
   * between a carrier and the shipper. Currently false: contacts are free to
   * any signed-in carrier. Drives the guest copy, which must not promise a
   * paywall that is switched off.
   */
  shipper_requires_subscription: boolean;
}

/**
 * One load, in full.
 *
 * Reached from the board by the opaque reference rather than an id, because
 * the board deliberately does not publish ids — see PublicLoadDetailResource.
 *
 * A signed-out visitor sees everything a carrier needs to judge whether the
 * freight is worth pursuing: the lane, the dates, the dimensions, the photos.
 * The brief and the budget are held back until there is an account behind the
 * request, and the page says so plainly rather than rendering holes.
 */
@Component({
  selector: 'fm-load-detail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Ripple, RouteMap],
  templateUrl: './load-detail.html',
  styleUrl: './load-detail.scss',
})
export class LoadDetail {
  protected readonly load = signal<LoadDetail | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly activeImage = signal(0);

  protected readonly auth = inject(AuthService);

  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);

  /**
   * The specification rows, built once rather than as a wall of @if blocks.
   * Empty values are dropped here so the template never renders a label with
   * nothing beside it.
   */
  protected readonly specs = computed(() => {
    const load = this.load();

    if (!load) {
      return [];
    }

    const rows: { label: string; value: string }[] = [
      { label: 'Freight type', value: load.category ?? '' },
      { label: 'Truck type', value: load.truck_type ?? '' },
      { label: 'Vehicle required', value: load.vehicle_type ?? '' },
      { label: 'Quantity', value: load.quantity ?? '' },
      // One row per axis rather than "L × W × H" on a single line. The board
      // has to be compact and the combined label earns its place there; this
      // page does not, and a carrier checking whether a load is over-width
      // should not have to parse a string to find the number.
      { label: 'Length', value: this.mm(load.length_mm) },
      { label: 'Width', value: this.mm(load.width_mm) },
      { label: 'Height', value: this.mm(load.height_mm) },
      {
        label: 'Weight',
        value: load.weight_tons ? `${load.weight_tons} t (${load.weight_kg} kg)` : '',
      },
      { label: 'Availability', value: load.availability ?? '' },
      { label: 'Ready from', value: this.date(load.pickup_date) },
      { label: 'Deliver by', value: this.date(load.delivery_date) },
      { label: 'Budget', value: this.budget(load) },
    ];

    return rows.filter((row) => row.value !== '');
  });

  /**
   * What the call to action says, and where it goes.
   *
   * Role-aware because "Quote on this load" is only true for one of them. A
   * carrier previously landed on `/carrier` — the dashboard — which is a dead
   * end for the single action this page exists to drive; the quote form is on
   * the board. A shipper looking at their own load is not quoting on it at
   * all, so they get their quotes instead.
   */
  protected readonly cta = computed(() => {
    const load = this.load();
    const id = load ? Number(load.ref.replace(/\D/g, '')) : 0;

    if (!this.auth.isAuthenticated()) {
      return { label: 'Sign up to enquire', link: '/register', showLogin: true };
    }

    switch (this.auth.role()) {
      case 'carrier':
        return { label: 'Send an enquiry', link: '/carrier/board', showLogin: false };
      case 'shipper':
        return { label: 'See enquiries on this load', link: `/shipper/jobs/${id}/quotes`, showLogin: false };
      case 'admin':
        return { label: 'Open in admin', link: '/admin/jobs', showLogin: false };
      default:
        return { label: 'Sign up to enquire', link: '/register', showLogin: true };
    }
  });

  /**
   * This page, for the auth links to hand back to.
   *
   * Signing in from a load used to land the carrier on their dashboard with
   * no trail back to the load they were about to price — they had to find it
   * on the board again, which is where people give up. Built from the route
   * parameter rather than `router.url` so it is fixed at construction and
   * cannot drift.
   */
  protected readonly returnTo: string;

  constructor() {
    const ref = this.route.snapshot.paramMap.get('ref') ?? '';

    this.returnTo = `/load-board/${ref}`;

    this.http
      .get<ApiEnvelope<LoadDetail>>(`${environment.apiUrl}/public/loads/${ref}`)
      .subscribe({
        next: (response) => {
          this.load.set(response.data);
          this.loading.set(false);
          this.applySeo(response.data);
        },
        error: (response) => {
          this.loading.set(false);
          this.error.set(
            describeError(response, 'That load is no longer on the board.'),
          );
        },
      });
  }

  /**
   * A dimension in metres, for the hero.
   *
   * Metres rather than millimetres up here because the hero is scanned, and
   * what a carrier is scanning for is whether the load clears the 2.5 m width
   * and 4.3 m height that make it oversize. The exact millimetre the shipper
   * measured is in the specification below, where it is read rather than
   * scanned.
   */
  protected metres(value: number | null): string {
    if (!value) {
      return '';
    }

    // Under a metre, metres are the wrong unit: a 2 mm entry rendered as
    // "0.00 m", which reads as a broken field rather than a small number.
    return value < 1000
      ? `${value.toLocaleString('en-AU')} mm`
      : `${(value / 1000).toFixed(2)} m`;
  }

  /**
   * A single dimension, in millimetres and metres.
   *
   * Both because they answer different questions: millimetres are what the
   * shipper measured and what a tight fit is judged on, metres are what a
   * carrier compares against the 2.5 m width and 4.3 m height that decide
   * whether a load is oversize and needs a permit.
   */
  protected mm(value: number | null): string {
    if (!value) {
      return '';
    }

    // The metres are the useful half against the 2.5 m and 4.3 m oversize
    // limits, and they say nothing at all below a metre.
    return value < 1000
      ? `${value.toLocaleString('en-AU')} mm`
      : `${value.toLocaleString('en-AU')} mm (${(value / 1000).toFixed(2)} m)`;
  }

  protected date(iso: string | null): string {
    if (!iso) {
      return '';
    }

    return new Date(iso).toLocaleDateString('en-AU', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  }

  /** "3 hours ago", without pulling in a date library for one label. */
  protected posted(iso: string | null): string {
    if (!iso) {
      return '';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 3600) {
      return 'just now';
    }

    const format = new Intl.RelativeTimeFormat('en-AU', { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
      ['day', 86400],
      ['hour', 3600],
    ];

    for (const [unit, size] of units) {
      if (seconds >= size) {
        return format.format(-Math.floor(seconds / size), unit);
      }
    }

    return 'just now';
  }

  private budget(load: LoadDetail): string {
    const money = (value: number) =>
      new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency: 'AUD',
        maximumFractionDigits: 0,
      }).format(value);

    if (load.budget_min !== null && load.budget_max !== null) {
      return `${money(load.budget_min)} – ${money(load.budget_max)}`;
    }

    if (load.budget_min !== null) {
      return `from ${money(load.budget_min)}`;
    }

    return load.budget_max !== null ? `up to ${money(load.budget_max)}` : '';
  }

  /**
   * A load page is a real landing page — a carrier searching "excavator
   * transport Sydney Dubbo" should be able to arrive here. The description is
   * built from the public fields only, since the brief itself is withheld from
   * the crawler for the same reason it is withheld from a stranger.
   */
  private applySeo(load: LoadDetail): void {
    const parts = [load.category, load.truck_type, load.dimensions_label]
      .filter(Boolean)
      .join(' · ');

    this.seo.apply({
      title: `${load.title} — ${load.pickup} to ${load.delivery} | FreightMove`,
      description:
        `Freight available: ${load.title}, ${load.pickup} to ${load.delivery}.` +
        (parts ? ` ${parts}.` : '') +
        ' Send an enquiry with FreightMove.',
      path: `/load-board/${load.ref}`,
    });
  }
}
