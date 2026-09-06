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
  imports: [RouterLink, Icon, Ripple],
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

  /** "Sydney to Dubbo" — the headline, and the SEO title. */
  protected readonly lane = computed(() => {
    const load = this.load();

    return load ? `${load.pickup} to ${load.delivery}` : '';
  });

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
      { label: 'Dimensions', value: load.dimensions_label ?? '' },
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
      return { label: 'Sign up to quote', link: '/register', showLogin: true };
    }

    switch (this.auth.role()) {
      case 'carrier':
        return { label: 'Quote on this load', link: '/carrier/board', showLogin: false };
      case 'shipper':
        return { label: 'See quotes on this load', link: `/shipper/jobs/${id}/quotes`, showLogin: false };
      case 'admin':
        return { label: 'Open in admin', link: '/admin/jobs', showLogin: false };
      default:
        return { label: 'Sign up to quote', link: '/register', showLogin: true };
    }
  });

  constructor() {
    const ref = this.route.snapshot.paramMap.get('ref') ?? '';

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
        ' Quote on this load with FreightMove.',
      path: `/load-board/${load.ref}`,
    });
  }
}
