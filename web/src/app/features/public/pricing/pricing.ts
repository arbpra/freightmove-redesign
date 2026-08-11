import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';

import { environment } from '../../../../environments/environment';
import { AuthService } from '../../../core/auth/auth.service';
import { Seo } from '../../../core/seo/seo.service';
import { Icon } from '../../../shared/icon';
import { Reveal } from '../../../shared/reveal.directive';
import { Ripple } from '../../../shared/ripple.directive';
import { SectionHead } from '../../../shared/section-head';
import { featuresFor, SubscriptionPlan } from '../../subscription/subscription.models';
import { SubscriptionService } from '../../subscription/subscription.service';

/**
 * The carrier pricing page, at the same path as the live site's.
 *
 * Prices, intervals and the advertised monthly saving all come from the API —
 * the saving is computed from the prices rather than written into the copy, so
 * the two can never disagree the way hardcoded marketing figures eventually do.
 */
@Component({
  selector: 'fm-pricing',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, Ripple, SectionHead],
  templateUrl: './pricing.html',
  styleUrl: './pricing.scss',
})
export class Pricing {
  protected readonly featuresFor = featuresFor;
  protected readonly auth = inject(AuthService);

  private readonly subscriptions = inject(SubscriptionService);

  protected readonly data = toSignal(this.subscriptions.plans$, { initialValue: null });

  protected readonly plans = computed<SubscriptionPlan[]>(() => {
    const all = this.data()?.items ?? [];

    // The trial only belongs on the page while the offer is open.
    return this.data()?.trial_offer_open ? all : all.filter((plan) => !plan.is_trial);
  });

  protected readonly faqs = signal([
    {
      q: 'What does a subscription get me?',
      a: 'Access to every load on the board, and the ability to quote on them. Shippers see your company, rating and verification badge against each quote you send.',
    },
    {
      q: 'Can I cancel?',
      a: 'Yes, at any time. You keep access until the end of the period you have already paid for — cancelling means the plan will not renew, not that access stops that day.',
    },
    {
      q: 'Does quoting cost extra?',
      a: 'No. Quoting is unlimited on every paid plan, and on the free trial.',
    },
  ]);

  constructor() {
    inject(Seo).apply({
      title: 'Carrier Subscriptions | FreightMove',
      description:
        'Subscribe to FreightMove and quote on freight across Australia. Monthly, quarterly and annual plans, plus a free two-month trial for new carriers.',
      path: '/carriers-subscription',
      structuredData: {
        '@context': 'https://schema.org',
        '@type': 'Service',
        name: 'FreightMove Carrier Subscription',
        serviceType: 'Freight marketplace access for carriers',
        areaServed: { '@type': 'Country', name: 'Australia' },
        provider: { '@type': 'Organization', name: 'FreightMove', url: environment.siteUrl },
      },
    });
  }

  /** Where the button goes depends on whether they can act on it yet. */
  protected ctaLink(): string {
    if (!this.auth.isAuthenticated()) {
      return '/register';
    }

    return this.auth.role() === 'carrier' ? '/carrier/subscription' : '/carrier';
  }

  protected priceLabel(plan: SubscriptionPlan): string {
    return plan.is_trial ? '$0.00' : `$${plan.price.toFixed(2)}`;
  }

  protected intervalLabel(plan: SubscriptionPlan): string {
    if (plan.is_trial) {
      return `${plan.interval_months} months free`;
    }

    return plan.interval_months === 1 ? 'per month' : `every ${plan.interval_months} months`;
  }
}
